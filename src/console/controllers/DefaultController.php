<?php

namespace roelvanhintum\assetusage\console\controllers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use verbb\hyper\records\ElementCache as HyperElementCache;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Controls
 */
class DefaultController extends Controller
{
    /**
     * Lists all unused assets.
     * @param string|null $volume The handle of the asset's volume.
     */
    public function actionListUnused(?string $volume = null)
    {
        $this->stdout('Listing all unused asset ids:' . PHP_EOL);

        $results = $this->getUnusedAssets($volume);
        foreach ($results as $result) {
            $this->stdout($result['id'] . ' : ' . $result['filename'] . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * Deletes all unused assets.
     * @param string|null $volume The handle of the asset's volume.
     */
    public function actionDeleteUnused(?string $volume = null)
    {
        $this->stdout('Deleting all unused asset ids:' . PHP_EOL);

        $results = $this->getUnusedAssets($volume);
        $assetCount = count($results);

        if ($this->confirm("Delete $assetCount assets?")) {
            $assets = Craft::$app->getAssets();

            foreach ($results as $result) {
                $this->stdout('Deleting ' . $result['id'] . ' : ' . $result['filename'] . PHP_EOL);

                $asset = $assets->getAssetById($result['id']);
                if ($asset) {
                    Craft::$app->getElements()->deleteElement($asset);
                }
            }

            $this->stdout('Deleted all unused asset ids.' . PHP_EOL);
        }

        return ExitCode::OK;
    }

    private function getUnusedAssets(?string $volume = null)
    {
        if ($volume) {
            /** @var craft\models\Volume */
            $volumeModel = Craft::$app->getVolumes()->getVolumeByHandle($volume);
        }

        $subQueryRelations = (new Query())
            ->select('id')
            ->from(['relations' => Table::RELATIONS])
            ->where('[[relations.targetId]]=[[assets.id]]')
            ->orWhere('[[relations.sourceId]]=[[assets.id]]');

        $subQueryContent = (new Query())
            ->select('elementId as id')
            ->from(Table::ELEMENTS_SITES);

        // PostgreSQL requires explicit casting for JSONB columns
        if (Craft::$app->getDb()->getIsPgsql()) {
            $subQueryContent
                ->where("CAST(content AS TEXT) LIKE CONCAT('%asset:', assets.id, ':%')")
                ->orWhere("CAST(content AS TEXT) LIKE CONCAT('%\"imageId\": \"', assets.id, '\",%')");
        } else {
            $subQueryContent
                ->where("`content` LIKE CONCAT('%asset:', assets.id, ':%')")
                ->orWhere("`content` LIKE CONCAT('%\"imageId\": \"', assets.id, '\",%')");
        }

        $query = (new Query())
            ->select(['assets.id', 'assets.filename'])
            ->from(['assets' => Table::ASSETS])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[assets.id]]')
            ->where(['elements.dateDeleted' => null])
            ->andWhere(['not exists', $subQueryRelations])
            ->andWhere(['not exists', $subQueryContent]);

        if (Craft::$app->getPlugins()->isPluginEnabled('hyper')) {
            $subQueryHyper = (new Query())
                ->select('id')
                ->from(['hyper_element_cache' => HyperElementCache::tableName()])
                ->where('[[hyper_element_cache.targetId]] = [[assets.id]]')
                ->andWhere(['targetType' => Asset::class]);
            $query->andWhere(['not exists', $subQueryHyper]);
        }

        if (isset($volumeModel)) {
            $query->where(['volumeId' => $volumeModel->id]);
        }

        return $query->all();
    }
}
