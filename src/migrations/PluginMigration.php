<?php
namespace verbb\hyper\migrations;

use verbb\hyper\events\ModifyMigrationLinkEvent;
use verbb\hyper\fieldlayoutelements\AriaLabelField;
use verbb\hyper\fieldlayoutelements\ClassesField;
use verbb\hyper\fieldlayoutelements\CustomAttributesField;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\fieldlayoutelements\LinkTitleField;
use verbb\hyper\fields\HyperField;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

use Exception;

use yii\console\Controller;
use yii\helpers\Markdown;

use verbb\vizy\Vizy;

class PluginMigration extends Migration
{
    // Constants
    // =========================================================================

    public const EVENT_MODIFY_LINK_TYPE = 'modifyLinkType';


    // Properties
    // =========================================================================

    public bool $resaveFields = true;
    public array $fields = [];
    public string $oldFieldTypeClass = '';

    private ?Controller $_consoleRequest = null;


    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        App::maxPowerCaptain();

        $this->fields = (new Query())
            ->from('{{%fields}}')
            ->where(['type' => $this->oldFieldTypeClass])
            ->all();

        $fieldService = Craft::$app->getFields();

        // Update the field settings
        $this->processFieldSettings();

        // Refresh the internal fields cache
        $fieldService->refreshFields();
        
        // Update the field content
        $this->processFieldContent();

        // Refresh the internal fields cache
        $fieldService->refreshFields();

        // Resave all fields to ensure they're properly saved in project config
        if ($this->resaveFields) {
            foreach ($this->fields as $fieldData) {
                $this->stdout("Re-saving field “{$fieldData['handle']}”.");

                $field = $fieldService->getFieldById($fieldData['id']);

                if (!$field) {
                    continue;
                }

                if (!$fieldService->saveField($field)) {
                    throw new Exception(Json::encode($field->getErrors()));
                }

                $this->stdout("    > Field “{$fieldData['handle']}” migration finalised." . PHP_EOL, Console::FG_GREEN);
            }
        }

        $this->stdout('Finished Migration' . PHP_EOL, Console::FG_GREEN);

        return true;
    }

    public function safeDown(): bool
    {
        return false;
    }

    public function setConsoleRequest($value): void
    {
        $this->_consoleRequest = $value;
    }

    public function getLinkType($oldClass): ?string
    {
        $newClass = $this->typeMap[$oldClass] ?? null;

        // Fire a 'modifyLinkType' event
        $event = new ModifyMigrationLinkEvent([
            'oldClass' => $oldClass,
            'newClass' => $newClass,
        ]);
        $this->trigger(self::EVENT_MODIFY_LINK_TYPE, $event);

        return $event->newClass;
    }

    public function processFieldContent(): void
    {
        foreach ($this->fields as $fieldData) {
            $this->stdout("Preparing to migrate field “{$fieldData['handle']}” ({$fieldData['uid']}) content.");

            // Fetch the field model because we'll need it later
            $field = Craft::$app->getFields()->getFieldById($fieldData['id']);

            if ($field) {
                $column = ElementHelper::fieldColumn($field->columnPrefix, $field->handle, $field->columnSuffix);

                // Handle global field content
                if ($field->context === 'global') {
                    $content = (new Query())
                        ->select([$column, 'id', 'elementId'])
                        ->from('{{%content}}')
                        ->where(['not', [$column => null]])
                        ->andWhere(['not', [$column => '']])
                        ->all();

                    foreach ($content as $row) {
                        $settings = $this->convertModel(Json::decode($row[$column]));

                        if ($settings) {
                            Db::update('{{%content}}', [$column => Json::encode($settings)], ['id' => $row['id']], [], true, $this->db);

                            $this->stdout('    > Migrated content #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_GREEN);
                        } else {
                            // Null model is okay, that's just an empty field content
                            if ($settings !== null) {
                                $this->stdout('    > Unable to convert content #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_RED);
                            }
                        }
                    }
                }

                // Handle Matrix field content
                if (str_contains($field->context, 'matrixBlockType')) {
                    // Get the Matrix field, and the content table
                    $blockTypeUid = explode(':', $field->context)[1];

                    $matrixInfo = (new Query())
                        ->select(['fieldId', 'handle'])
                        ->from('{{%matrixblocktypes}}')
                        ->where(['uid' => $blockTypeUid])
                        ->one();

                    if ($matrixInfo) {
                        $matrixFieldId = $matrixInfo['fieldId'];
                        $matrixBlockTypeHandle = $matrixInfo['handle'];

                        $matrixField = Craft::$app->getFields()->getFieldById($matrixFieldId);

                        if ($matrixField) {
                            $column = ElementHelper::fieldColumn($field->columnPrefix, $matrixBlockTypeHandle . '_' . $field->handle, $field->columnSuffix);

                            $content = (new Query())
                                ->select([$column, 'id', 'elementId'])
                                ->from($matrixField->contentTable)
                                ->where(['not', [$column => null]])
                                ->andWhere(['not', [$column => '']])
                                ->all();

                            foreach ($content as $row) {
                                $settings = $this->convertModel(Json::decode($row[$column]));
                                
                                if ($settings) {
                                    Db::update($matrixField->contentTable, [$column => Json::encode($settings)], ['id' => $row['id']], [], true, $this->db);
                                
                                    $this->stdout('    > Migrated “' . $field->handle . ':' . $matrixBlockTypeHandle . '” Matrix content #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_GREEN);
                                } else {
                                    // Null model is okay, that's just an empty field content
                                    if ($settings !== null) {
                                        $this->stdout('    > Unable to convert Matrix content “' . $field->handle . ':' . $matrixBlockTypeHandle . '” #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_RED);
                                    }
                                }
                            }
                        }
                    }
                }

                // Handle Super Table field content
                if (str_contains($field->context, 'superTableBlockType')) {
                    // Get the Super Table field, and the content table
                    $blockTypeUid = explode(':', $field->context)[1];

                    $superTableFieldId = (new Query())
                        ->select(['fieldId'])
                        ->from('{{%supertableblocktypes}}')
                        ->where(['uid' => $blockTypeUid])
                        ->scalar();

                    $superTableField = Craft::$app->getFields()->getFieldById($superTableFieldId);

                    if ($superTableField) {
                        $column = ElementHelper::fieldColumn($field->columnPrefix, $field->handle, $field->columnSuffix);

                        $content = (new Query())
                            ->select([$column, 'id', 'elementId'])
                            ->from($superTableField->contentTable)
                            ->where(['not', [$column => null]])
                            ->andWhere(['not', [$column => '']])
                            ->all();

                        foreach ($content as $row) {
                            $settings = $this->convertModel(Json::decode($row[$column]));

                            if ($settings) {
                                Db::update($superTableField->contentTable, [$column => Json::encode($settings)], ['id' => $row['id']], [], true, $this->db);
                            
                                $this->stdout('    > Migrated “' . $field->handle . '” Super Table content #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_GREEN);
                            } else {
                                // Null model is okay, that's just an empty field content
                                if ($settings !== null) {
                                    $this->stdout('    > Unable to convert Super Table content “' . $field->handle . '” #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_RED);
                                }
                            }
                        }
                    }
                }
            }

            // Check for Vizy fields, a little different
            if ($this->isPluginInstalledAndEnabled('vizy')) {
                $this->migrateVizyContent($fieldData);
            }

            $this->stdout("    > Field “{$field['handle']}” content migrated." . PHP_EOL, Console::FG_GREEN);
        }
    }

    public static function getDefaultFieldLayout(bool $includeText = true, bool $enableTitle = true, bool $enableAriaLabel = false): FieldLayout
    {
        $fieldLayout = new FieldLayout([
            'type' => static::class,
        ]);

        // Populate the field layout
        $tab1 = new FieldLayoutTab(['name' => 'Content']);
        $tab1->setLayout($fieldLayout);

        $linkField = Craft::createObject([
            'class' => LinkField::class,
            'width' => 50,
        ]);

        $linkTextField = $includeText ? Craft::createObject([
            'class' => LinkTextField::class,
            'width' => 50,
        ]) : null;

        $tab1->setElements(array_filter([$linkField, $linkTextField]));

        $tab2 = new FieldLayoutTab(['name' => 'Advanced']);
        $tab2->setLayout($fieldLayout);

        $linkTitleField = $enableTitle ? Craft::createObject([
            'class' => LinkTitleField::class,
        ]) : null;

        $classesField = Craft::createObject([
            'class' => ClassesField::class,
        ]);

        $customAttributesField = Craft::createObject([
            'class' => CustomAttributesField::class,
        ]);

        $ariaLabelField = $enableAriaLabel ? Craft::createObject([
            'class' => AriaLabelField::class,
        ]) : null;
        
        $tab2->setElements(array_filter([$linkTitleField, $classesField, $customAttributesField, $ariaLabelField]));

        $fieldLayout->setTabs([$tab1, $tab2]);

        return $fieldLayout;
    }

    public function prepLinkTypes(HyperField $field): void
    {
        $linkTypes = [];

        foreach ($field->linkTypes as $linkType) {
            $linkTypes[] = $linkType->getSettingsConfig();
        }

        $field->linkTypes = $linkTypes;
    }

    public function migrateVizyContent($fieldData): void
    {
        Vizy::$plugin->getContent()->modifyFieldContent($fieldData['uid'], function($handle, $data) {
            // We need to flatten the data to deal with deeply-nested content like when in Matrix/Super Table.
            foreach (self::flatten($data) as $flatKey => $flatContent) {
                $searchKey = 'fields.' . $handle;

                // Find from the end of the block path `fields.myLinkField`
                if (str_ends_with($flatKey, $searchKey)) {
                    // Sometimes stored as a JSON string
                    if (is_string($flatContent)) {
                        $flatContent = Json::decodeIfJson($flatContent);
                    }

                    if ($newContent = $this->convertModel($flatContent)) {
                        ArrayHelper::setValue($data, $flatKey, $newContent);
                    }
                }
            }

            return $data;
        }, $this->db);
    }

    public function isPluginInstalledAndEnabled(string $plugin): bool
    {
        $pluginsService = Craft::$app->getPlugins();

        // Ensure that we check if initialized, installed and enabled. 
        // The plugin might be installed but disabled, or installed and enabled, but missing plugin files.
        return $pluginsService->isPluginInstalled($plugin) && $pluginsService->isPluginEnabled($plugin) && $pluginsService->getPlugin($plugin);
    }

    public function stdout($string, $color = ''): void
    {
        if ($this->_consoleRequest) {
            $this->_consoleRequest->stdout($string . PHP_EOL, $color);
        } else {
            $class = '';

            if ($color) {
                $class = 'color-' . $color;
            }

            echo '<div class="log-label ' . $class . '">' . Markdown::processParagraph($string) . '</div>';
        }
    }

    public function getExceptionTraceAsString($exception): string
    {
        $rtn = "";
        $count = 0;

        foreach ($exception->getTrace() as $frame) {
            $args = "";

            if (isset($frame['args'])) {
                $args = [];

                foreach ($frame['args'] as $arg) {
                    if (is_string($arg)) {
                        $args[] = "'" . $arg . "'";
                    } else if (is_array($arg)) {
                        $args[] = "Array";
                    } else if (is_null($arg)) {
                        $args[] = 'NULL';
                    } else if (is_bool($arg)) {
                        $args[] = ($arg) ? "true" : "false";
                    } else if (is_object($arg)) {
                        $args[] = get_class($arg);
                    } else if (is_resource($arg)) {
                        $args[] = get_resource_type($arg);
                    } else {
                        $args[] = $arg;
                    }
                }

                $args = implode(", ", $args);
            }

            $rtn .= sprintf("#%s %s(%s): %s(%s)\n",
                $count,
                $frame['file'] ?? '[internal function]',
                $frame['line'] ?? '',
                (isset($frame['class'])) ? $frame['class'] . $frame['type'] . $frame['function'] : $frame['function'],
                $args);

            $count++;
        }

        return $rtn;
    }

    public static function flatten(array $data, string $separator = '.'): array
    {
        $result = [];
        $stack = [];
        $path = '';

        reset($data);
        while (!empty($data)) {
            $key = key($data);
            $element = $data[$key];
            unset($data[$key]);

            if (is_array($element) && !empty($element)) {
                if (!empty($data)) {
                    $stack[] = [$data, $path];
                }
                $data = $element;
                reset($data);
                $path .= $key . $separator;
            } else {
                $result[$path . $key] = $element;
            }

            if (empty($data) && !empty($stack)) {
                [$data, $path] = array_pop($stack);
                reset($data);
            }
        }

        return $result;
    }
}
