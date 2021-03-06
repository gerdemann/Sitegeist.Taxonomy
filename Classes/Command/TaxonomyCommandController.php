<?php
namespace Sitegeist\Taxonomy\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ImportExport\NodeExportService;
use Neos\ContentRepository\Domain\Service\ImportExport\NodeImportService;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Persistence\Generic\PersistenceManager;
use Neos\Flow\ResourceManagement\PersistentResource;
use Sitegeist\Taxonomy\Service\DimensionService;
use Sitegeist\Taxonomy\Service\TaxonomyService;

/**
 * @Flow\Scope("singleton")
 */
class TaxonomyCommandController extends CommandController
{

    /**
     * @var array
     * @Flow\InjectConfiguration
     */
    protected $configuration;

    /**
     * @Flow\Inject
     * @var NodeImportService
     */
    protected $nodeImportService;

    /**
     * @var NodeExportService
     * @Flow\Inject
     */
    protected $nodeExportService;

    /**
     * @var NodeDataRepository
     * @Flow\Inject
     */
    protected $nodeDataRepository;

    /**
     * @var TaxonomyService
     * @Flow\Inject
     */
    protected $taxonomyService;

    /**
     * @var DimensionService
     * @Flow\Inject
     */
    protected $dimensionService;

    /**
     * @var PersistenceManager
     * @Flow\Inject
     */
    protected $persistenceManager;

    /**
     * List taxonomy vocabularies
     *
     * @param string $vocabularyNode vocabularay nodename(path) to prune (globbing is supported)
     * @return void
     */
    public function listCommand()
    {
        $taxonomyRoot = $this->taxonomyService->getRoot();

        /**
         * @var NodeInterface[] $vocabularyNodes
         */
        $vocabularyNodes = (new FlowQuery([$taxonomyRoot]))
            ->children('[instanceof ' . $this->taxonomyService->getVocabularyNodeType() . ' ]')
            ->get();

        /**
         * @var NodeInterface $vocabularyNode
         */
        foreach ($vocabularyNodes as $vocabularyNode) {
            $this->outputLine($vocabularyNode->getName());
        }
    }

    /**
     * Import taxonomy content
     *
     * @param string $filename relative path and filename to the XML file to read.
     * @param string $vocabularyNode vocabularay nodename(path) to import (globbing is supported)
     * @return void
     */
    public function importCommand($filename, $vocabulary = null)
    {
        $xmlReader = new \XMLReader();
        $xmlReader->open($filename, null, LIBXML_PARSEHUGE);

        $taxonomyRoot = $this->taxonomyService->getRoot();

        while ($xmlReader->read()) {
            if ($xmlReader->nodeType != \XMLReader::ELEMENT || $xmlReader->name !== 'vocabulary') {
                continue;
            }

            $vocabularyName = (string) $xmlReader->getAttribute('name');
            if (is_string($vocabulary) && fnmatch($vocabulary, $vocabularyName) == false) {
                continue;
            }

            $this->nodeImportService->import($xmlReader, $taxonomyRoot->getPath());
            $this->outputLine('Imported vocabulary %s from file %s', [$vocabularyName, $filename]);
        }
    }

    /**
     * Export taxonomy content
     *
     * @param string $filename filename for the xml that is written.
     * @param string $vocabularyNode vocabularay nodename(path) to export (globbing is supported)
     * @return void
     */
    public function exportCommand($filename, $vocabulary = null)
    {
        $xmlWriter = new \XMLWriter();
        $xmlWriter->openUri($filename);
        $xmlWriter->setIndent(true);

        $xmlWriter->startDocument('1.0', 'UTF-8');
        $xmlWriter->startElement('root');

        $taxonomyRoot = $this->taxonomyService->getRoot();

        /**
         * @var NodeInterface[] $vocabularyNodes
         */
        $vocabularyNodes = (new FlowQuery([$taxonomyRoot]))
            ->children('[instanceof ' . $this->taxonomyService->getVocabularyNodeType() . ' ]')
            ->get();

        /**
         * @var NodeInterface $vocabularyNode
         */
        foreach ($vocabularyNodes as $vocabularyNode) {
            $vocabularyName = $vocabularyNode->getName();
            if (is_string($vocabulary) && fnmatch($vocabulary, $vocabularyName) == false) {
                continue;
            }
            $xmlWriter->startElement('vocabulary');
            $xmlWriter->writeAttribute('name', $vocabularyName);
            $this->nodeExportService->export($vocabularyNode->getPath(), 'live', $xmlWriter,  false, false);
            $this->outputLine('Exported vocabulary %s to file %s', [$vocabularyName, $filename]);
            $xmlWriter->endElement();
        }

        $xmlWriter->endElement();
        $xmlWriter->endDocument();

        $xmlWriter->flush();
    }

    /**
     * Prune taxonomy content
     *
     * @param string $vocabularyNode vocabularay nodename(path) to prune (globbing is supported)
     * @return void
     */
    public function pruneCommand($vocabulary)
    {
        $taxonomyRoot = $this->taxonomyService->getRoot();

        /**
         * @var NodeInterface[] $vocabularyNodes
         */
        $vocabularyNodes = (new FlowQuery([$taxonomyRoot]))
            ->children('[instanceof ' . $this->taxonomyService->getVocabularyNodeType() . ' ]')
            ->get();

        /**
         * @var NodeInterface $vocabularyNode
         */
        foreach ($vocabularyNodes as $vocabularyNode) {
            $vocabularyName = $vocabularyNode->getName();
            if (is_string($vocabulary) && fnmatch($vocabulary, $vocabularyName) == false) {
                continue;
            }
            $this->nodeDataRepository->removeAllInPath($vocabularyNode->getPath());
            $dimensionNodes = $this->nodeDataRepository->findByPath($vocabularyNode->getPath());
            foreach ($dimensionNodes as $node) {
                $this->nodeDataRepository->remove($node);
            }

            $this->outputLine('Pruned vocabulary %s', [$vocabularyName]);
        }
    }

    /**
     * Reset a taxonimy dimension and create fresh variants from the base dimension
     *
     * @param string $dimensionName
     * @param string $dimensionValue
     * @return void
     */
    public function pruneDimensionCommand($dimensionName, $dimensionValue)
    {
        $taxonomyRoot = $this->taxonomyService->getRoot();
        $targetSubgraph = $this->dimensionService->getDimensionSubgraphByTargetValues([$dimensionName => $dimensionValue]);

        if (!$targetSubgraph) {
            $this->outputLine('Target subgraph not found');
            $this->quit(1);
        }

        $targetContextValues = $this->dimensionService->getDimensionValuesForSubgraph($targetSubgraph);
        $flowQuery = new FlowQuery([$taxonomyRoot]);
        $taxonomyRootInTargetContest = $flowQuery->context($targetContextValues)->get(0);

        if (!$taxonomyRootInTargetContest) {
            $this->outputLine('Not root in target context found');
            $this->quit(1);
        }

        if ($taxonomyRootInTargetContest == $taxonomyRoot) {
            $this->outputLine('The root is the default context and cannot be pruned');
            $this->quit(1);
        }

        $this->outputLine('Removing content all below ' . $taxonomyRootInTargetContest->getContextPath());
        $flowQuery = new FlowQuery([$taxonomyRootInTargetContest]);
        $allNodes = $flowQuery->find('[instanceof ' . $this->taxonomyService->getVocabularyNodeType() . '],[instanceof ' . $this->taxonomyService->getTaxonomyNodeType() . ']')->get();
        foreach ($allNodes as $node) {
            $this->outputLine(' - remove: ' . $node->getContextPath());
            $node->remove();
        }
        $this->outputLine('Done');
    }

    /**
     * Make sure all values from default are present in the target dimension aswell
     *
     * @param string $dimensionName
     * @param string $dimensionValue
     * @return void
     */
    public function populateDimensionCommand($dimensionName, $dimensionValue)
    {
        $taxonomyRoot = $this->taxonomyService->getRoot();
        $targetSubgraph = $this->dimensionService->getDimensionSubgraphByTargetValues([$dimensionName => $dimensionValue]);

        if (!$targetSubgraph) {
            $this->outputLine('Target subgraph not found');
            $this->quit(1);
        }

        $targetContextValues = $this->dimensionService->getDimensionValuesForSubgraph($targetSubgraph);
        $flowQuery = new FlowQuery([$taxonomyRoot]);
        $taxonomyRootInTargetContest = $flowQuery->context($targetContextValues)->get(0);

        if (!$taxonomyRootInTargetContest) {
            $this->outputLine('Not root in target context found');
            $this->quit(1);
        }

        if ($taxonomyRootInTargetContest == $taxonomyRoot) {
            $this->outputLine('The root is the default context and cannot be recreated');
            $this->quit(1);
        }

        if ($taxonomyRootInTargetContest == $taxonomyRoot) {
            $this->outputLine('The root is the default context and cannot be recreated');
            $this->quit(1);
        }

        $this->outputLine('Populating taxonomy content from default below' . $taxonomyRootInTargetContest->getContextPath());
        $targetContext = $taxonomyRootInTargetContest->getContext();
        $flowQuery = new FlowQuery([$taxonomyRoot]);
        $allNodes = $flowQuery->find('[instanceof ' . $this->taxonomyService->getVocabularyNodeType() . '],[instanceof ' . $this->taxonomyService->getTaxonomyNodeType() . ']')->get();
        foreach ($allNodes as $node) {
            $this->outputLine(' - adopt: ' . $node->getContextPath());
            $targetContext->adoptNode($node);
        }
        $this->outputLine('Done');
    }
}
