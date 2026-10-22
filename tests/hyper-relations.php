<?php

// Run with `php tests/hyper-relations.php` after installing Composer dependencies.
// Requires pdo_sqlite; all queries use an in-memory database.
$loader = require getenv('ASSET_USAGE_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
require_once dirname($loader->findFile(yii\base\Application::class), 2) . '/Yii.php';
require_once dirname($loader->findFile(craft\db\Query::class), 2) . '/Craft.php';

use craft\elements\Asset as AssetElement;
use roelvanhintum\assetusage\console\controllers\DefaultController;
use roelvanhintum\assetusage\services\Asset;
use verbb\hyper\records\ElementCache;
use verbb\hyper\records\LinkRelation;
use yii\console\Application;
use yii\db\Connection;

final class TestConnection extends Connection
{
    public function getIsPgsql(): bool
    {
        return false;
    }
}

final class TestApplication extends Application
{
    public bool $hyperEnabled = false;

    public function getPlugins(): object
    {
        return new class($this->hyperEnabled) {
            public function __construct(private bool $enabled)
            {
            }

            public function isPluginEnabled(string $handle): bool
            {
                return $handle === 'hyper' && $this->enabled;
            }
        };
    }

    public function getVolumes(): object
    {
        return new class {
            public function getVolumeByHandle(string $handle): object
            {
                return (object)['id' => 10];
            }
        };
    }
}

$checks = 0;
$check = static function(mixed $actual, mixed $expected, string $message) use (&$checks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . var_export($actual, true));
    }

    $checks++;
};

$app = new TestApplication([
    'id' => 'assetusage-hyper-test',
    'basePath' => __DIR__,
    'components' => [
        'db' => ['class' => TestConnection::class, 'dsn' => 'sqlite::memory:', 'tablePrefix' => 'test_'],
    ],
]);
$db = $app->getDb();
$db->open();
$db->pdo->sqliteCreateFunction('CONCAT', static fn(...$parts) => implode('', $parts));

foreach ([
    'assets' => ['id' => 'integer', 'filename' => 'text', 'volumeId' => 'integer'],
    'elements' => ['id' => 'integer', 'dateDeleted' => 'text'],
    'relations' => ['id' => 'integer', 'sourceId' => 'integer', 'sourceSiteId' => 'integer', 'targetId' => 'integer'],
    'elements_sites' => ['elementId' => 'integer', 'siteId' => 'integer', 'content' => 'text'],
] as $table => $columns) {
    $db->createCommand()->createTable('{{%' . $table . '}}', $columns)->execute();
}

// Include an unused asset in another volume, ordinary relations/content, and trash.
foreach ([1 => 10, 2 => 10, 3 => 10, 4 => 20, 5 => 10, 6 => 10, 7 => 10] as $id => $volumeId) {
    $db->createCommand()->insert('{{%assets}}', ['id' => $id, 'filename' => "$id.jpg", 'volumeId' => $volumeId])->execute();
    $db->createCommand()->insert('{{%elements}}', ['id' => $id, 'dateDeleted' => $id === 7 ? '2026-01-01' : null])->execute();
}
$db->createCommand()->insert('{{%relations}}', ['id' => 1, 'sourceId' => 100, 'sourceSiteId' => 1, 'targetId' => 5])->execute();
$db->createCommand()->insert('{{%elements_sites}}', ['elementId' => 101, 'siteId' => 1, 'content' => 'asset:6:url'])->execute();

$service = new Asset();
$queryHyper = new ReflectionMethod($service, 'queryHyperRelations');
$asset = (new ReflectionClass(AssetElement::class))->newInstanceWithoutConstructor();
$asset->id = 1;
$controller = new DefaultController('assetusage', $app);
$queryUnused = new ReflectionMethod($controller, 'getUnusedAssets');
$unusedIds = static function(?string $volume = null) use ($queryUnused, $controller): array {
    $ids = array_map('intval', array_column($queryUnused->invoke($controller, $volume), 'id'));
    sort($ids);
    return $ids;
};

// No Hyper tables exist here, so accidental queries fail instead of returning empty rows.
$check($queryHyper->invoke($service, $asset), [], 'Disabled/absent Hyper returns no relations');
$check($unusedIds(), [1, 2, 3, 4], 'Disabled/absent Hyper preserves ordinary usage exclusions');
$check($unusedIds('images'), [1, 2, 3], 'Volume filter preserves ordinary usage exclusions');

if (class_exists(LinkRelation::class) || class_exists(ElementCache::class)) {
    $isHyper3 = class_exists(LinkRelation::class);
    $table = $isHyper3 ? LinkRelation::tableName() : ElementCache::tableName();
    $ownerColumn = $isHyper3 ? 'ownerId' : 'sourceId';
    $siteColumn = $isHyper3 ? 'ownerSiteId' : 'sourceSiteId';
    $db->createCommand()->createTable($table, [
        'id' => 'integer', $ownerColumn => 'integer', $siteColumn => 'integer',
        'targetId' => 'integer', 'targetType' => 'text',
    ])->execute();
    $app->hyperEnabled = true;

    $check($queryHyper->invoke($service, $asset), [], 'Enabled Hyper with no links returns no relations');
    $check($unusedIds(), [1, 2, 3, 4], 'An empty Hyper index excludes no assets');

    foreach ([[1, 100, 1, 1, AssetElement::class], [2, 200, 2, 1, AssetElement::class], [3, 300, 1, 3, 'craft\\elements\\Entry']] as $row) {
        $db->createCommand()->insert($table, array_combine(['id', $ownerColumn, $siteColumn, 'targetId', 'targetType'], $row))->execute();
    }

    $rows = $queryHyper->invoke($service, $asset);
    $rows = array_map(static fn(array $row) => array_map('intval', $row), $rows);
    usort($rows, static fn(array $a, array $b) => $a['id'] <=> $b['id']);
    $check($rows, [['id' => 100, 'siteId' => 1], ['id' => 200, 'siteId' => 2]], 'Hyper owners retain their source site');
    $asset->id = 2;
    $check($queryHyper->invoke($service, $asset), [], 'An unrelated asset has no Hyper usage');
    $asset->id = 3;
    $check($queryHyper->invoke($service, $asset), [], 'Non-asset targets do not count as asset usage');
    $check($unusedIds(), [2, 3, 4], 'Hyper-linked assets are excluded from unused results');
    $check($unusedIds('images'), [2, 3], 'Volume filter preserves Hyper and ordinary usage exclusions');

    $app->hyperEnabled = false;
    $db->createCommand()->dropTable($table)->execute();
    $check($queryHyper->invoke($service, $asset), [], 'Disabling installed Hyper stops relation queries');
    $check($unusedIds(), [1, 2, 3, 4], 'Disabling installed Hyper stops console relation queries');

    echo 'Hyper ' . ($isHyper3 ? '3' : '2') . ': ';
} else {
    echo 'Hyper absent: ';
}

echo "$checks checks passed on PHP " . PHP_VERSION . PHP_EOL;
