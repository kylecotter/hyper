<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use Craft;
use craft\db\Query;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;

use Exception;

use lenz\linkfield\fields\LinkField;

class MigrateTypedLink extends PluginMigration
{
    // Properties
    // =========================================================================

    public array $typeMap = [
        'asset' => linkTypes\Asset::class,
        'category' => linkTypes\Category::class,
        'custom' => linkTypes\Custom::class,
        'email' => linkTypes\Email::class,
        'entry' => linkTypes\Entry::class,
        'site' => linkTypes\Site::class,
        'tel' => linkTypes\Phone::class,
        'url' => linkTypes\Url::class,
        'user' => linkTypes\User::class,
    ];

    public string $oldFieldTypeClass = LinkField::class;
    public bool $resaveFields = false;


    // Public Methods
    // =========================================================================

    public function processFieldSettings(): void
    {
        $fieldService = Craft::$app->getFields();

        foreach ($this->fields as $field) {
            $this->stdout("Preparing to migrate field “{$field['handle']}” ({$field['uid']}).");

            $settings = Json::decode($field['settings']);
            $allowCustomText = $settings['allowCustomText'] ?? true;
            $allowTarget = $settings['allowTarget'] ?? true;
            $customTextRequired = $settings['customTextRequired'] ?? true;
            $defaultLinkName = $settings['defaultLinkName'] ?? '';
            $defaultText = $settings['defaultText'] ?? '';
            $enableAllLinkTypes = $settings['enableAllLinkTypes'] ?? true;
            $enableAriaLabel = $settings['enableAriaLabel'] ?? true;
            $enableTitle = $settings['enableTitle'] ?? true;

            $types = [];

            foreach (($settings['typeSettings'] ?? []) as $key => $type) {
                $linkTypeClass = $this->getLinkType($key);

                if (!$linkTypeClass) {
                    continue;
                }

                $linkType = new $linkTypeClass();
                $linkType->label = $linkType::displayName();
                $linkType->handle = 'default-' . StringHelper::toKebabCase($linkTypeClass);
                $linkType->enabled = $enableAllLinkTypes || ($type['enabled'] ?? false);
                $linkType->linkText = $defaultText;

                if ($linkType instanceof ElementLink) {
                    $linkType->sources = $type['sources'] ?? '*';
                } else if ($linkType instanceof linkTypes\Site) {
                    $linkType->sites = $type['sites'] ?? null;

                    if (is_array($linkType->sites)) {
                        foreach ($linkType->sites as $siteKey => $siteId) {
                            if ($site = Craft::$app->getSites()->getSiteById($siteId)) {
                                $linkType->sites[$siteKey] = $site->uid;
                            }
                        }
                    }
                }

                $fieldLayout = self::getDefaultFieldLayout($allowCustomText, $enableTitle, $enableAriaLabel);
                $linkType->layoutUid = StringHelper::UUID();
                $linkType->layoutConfig = $fieldLayout->getConfig();

                $types[] = $linkType->getSettingsConfig();
            }

            // Create a new Hyper field instance to have the settings validated correctly
            $newFieldConfig = $field;
            unset($newFieldConfig['type'], $newFieldConfig['settings']);

            $newFieldConfig['newWindow'] = $allowTarget;
            $newFieldConfig['defaultLinkType'] = $this->getLinkType($defaultLinkName) ? 'default-' . StringHelper::toKebabCase($this->getLinkType($defaultLinkName)) : null;
            $newFieldConfig['linkTypes'] = $types;

            $newField = new HyperField($newFieldConfig);
            $newField->columnSuffix = StringHelper::randomString(8);

            if (!$newField->validate()) {
                $this->stdout(Json::encode($newField->getErrors()) . PHP_EOL, Console::FG_RED);

                continue;
            }

            // We have to save the field instead of a settings update, because the plugin doesn't use the content table
            if ($newField->context === 'global') {
                if (!$fieldService->saveField($newField)) {
                    throw new Exception(Json::encode($newField->getErrors()));
                }
            }

            if (str_contains($newField->context, 'matrixBlockType')) {
                // Get the Matrix field, and the content table
                $blockTypeUid = explode(':', $newField->context)[1];

                $matrixFieldId = (new Query())
                    ->select(['fieldId'])
                    ->from('{{%matrixblocktypes}}')
                    ->where(['uid' => $blockTypeUid])
                    ->scalar();

                if ($matrixFieldId) {
                    $matrixField = Craft::$app->getFields()->getFieldById($matrixFieldId);

                    if ($matrixField) {
                        $this->migrateBlockField($matrixField, $newField);

                        if (!$fieldService->saveField($matrixField)) {
                            throw new Exception(Json::encode($matrixField->getErrors()));
                        }
                    } else {
                        $this->stdout("    > Unable to find owner Matrix field for ID “{$matrixFieldId}”." . PHP_EOL, Console::FG_RED);
                    }
                } else {
                    $this->stdout("    > Unable to find owner Matrix field for context “{$newField->context}”." . PHP_EOL, Console::FG_RED);
                }
            }

            if (str_contains($newField->context, 'superTableBlockType')) {
                // Get the Super Table field, and the content table
                $blockTypeUid = explode(':', $newField->context)[1];

                $superTableFieldId = (new Query())
                    ->select(['fieldId'])
                    ->from('{{%supertableblocktypes}}')
                    ->where(['uid' => $blockTypeUid])
                    ->scalar();

                if ($superTableFieldId) {
                    $superTableField = Craft::$app->getFields()->getFieldById($superTableFieldId);

                    if ($superTableField) {
                        $this->migrateBlockField($superTableField, $newField);

                        if (!$fieldService->saveField($superTableField)) {
                            throw new Exception(Json::encode($superTableField->getErrors()));
                        }
                    } else {
                        $this->stdout("    > Unable to find owner Super Table field for ID “{$superTableFieldId}”." . PHP_EOL, Console::FG_RED);
                    }
                } else {
                    $this->stdout("    > Unable to find owner Super Table field for context “{$newField->context}”." . PHP_EOL, Console::FG_RED);
                }
            }

            // Check for Vizy fields, a little different
            if ($this->isPluginInstalledAndEnabled('vizy')) {
                $this->migrateVizyContent($field);
            }

            $this->stdout("    > Field “{$field['handle']}” migrated." . PHP_EOL, Console::FG_GREEN);
        }
    }

    public function processFieldContent(): void
    {
        foreach ($this->fields as $fieldData) {
            $this->stdout("Preparing to migrate field “{$fieldData['handle']}” ({$fieldData['uid']}) content.");

            // Fetch the field model because we'll need it later
            $field = Craft::$app->getFields()->getFieldById($fieldData['id']);

            if ($field) {
                $content = (new Query())
                    ->select(['*'])
                    ->from('{{%lenz_linkfield}}')
                    ->where(['fieldId' => $field['id']])
                    ->all();

                // Find the empty content column (created when we saved the field for the new type)
                $column = ElementHelper::fieldColumn($field->columnPrefix, $field->handle, $field->columnSuffix);

                // Handle global field content
                if ($field->context === 'global') {
                    foreach ($content as $row) {
                        $settings = $this->convertModel($row);

                        // Find the content row to update
                        $contentRow = (new Query())
                            ->select(['id', 'elementId'])
                            ->from('{{%content}}')
                            ->where(['elementId' => $row['elementId'], 'siteId' => $row['siteId']])
                            ->one();

                        if ($contentRow) {
                            if ($settings) {
                                Db::update('{{%content}}', [$column => Json::encode($settings)], ['id' => $contentRow['id']], [], true, $this->db);

                                $this->stdout('    > Migrated content #' . $contentRow['id'] . ' for element #' . $contentRow['elementId'], Console::FG_GREEN);
                            } else {
                                // Null model is okay, that's just an empty field content
                                if ($settings !== null) {
                                    $this->stdout('    > Unable to convert content #' . $contentRow['id'] . ' for element #' . $contentRow['elementId'], Console::FG_RED);
                                }
                            }
                        } else {
                            $this->stdout('    > Unable to find content row for element #' . $row['elementId'] . ' and site #' . $row['siteId'], Console::FG_RED);
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

                        $column = ElementHelper::fieldColumn($field->columnPrefix, $matrixBlockTypeHandle . '_' . $field->handle, $field->columnSuffix);

                        if ($matrixField) {
                            foreach ($content as $row) {
                                $settings = $this->convertModel($row);

                                // Find the content row to update
                                $contentRow = (new Query())
                                    ->select(['id', 'elementId'])
                                    ->from($matrixField->contentTable)
                                    ->where(['elementId' => $row['elementId'], 'siteId' => $row['siteId']])
                                    ->one();

                                if ($contentRow) {
                                    if ($settings) {
                                        Db::update($matrixField->contentTable, [$column => Json::encode($settings)], ['id' => $contentRow['id']], [], true, $this->db);

                                        $this->stdout('    > Migrated “' . $field->handle . ':' . $matrixBlockTypeHandle . '” Matrix content #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_GREEN);
                                    } else {
                                        // Null model is okay, that's just an empty field content
                                        if ($settings !== null) {
                                            $this->stdout('    > Unable to convert Matrix content “' . $field->handle . ':' . $matrixBlockTypeHandle . '” #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_RED);
                                        }
                                    }
                                } else {
                                    $this->stdout('    > Unable to find Matrix content row for element #' . $row['elementId'] . ' and site #' . $row['siteId'], Console::FG_RED);
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
                        foreach ($content as $row) {
                            $settings = $this->convertModel($row);

                            // Find the content row to update
                            $contentRow = (new Query())
                                ->select(['id', 'elementId'])
                                ->from($superTableField->contentTable)
                                ->where(['elementId' => $row['elementId'], 'siteId' => $row['siteId']])
                                ->one();

                            if ($contentRow) {
                                if ($settings) {
                                    Db::update($superTableField->contentTable, [$column => Json::encode($settings)], ['id' => $contentRow['id']], [], true, $this->db);

                                    $this->stdout('    > Migrated “' . $field->handle . '” Super Table content #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_GREEN);
                                } else {
                                    // Null model is okay, that's just an empty field content
                                    if ($settings !== null) {
                                        $this->stdout('    > Unable to convert Super Table content “' . $field->handle . '” #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_RED);
                                    }
                                }
                            } else {
                                $this->stdout('    > Unable to find Super Table content row for element #' . $row['elementId'] . ' and site #' . $row['siteId'], Console::FG_RED);
                            }
                        }
                    }
                }
            }

            $this->stdout("    > Field “{$fieldData['handle']}” content migrated." . PHP_EOL, Console::FG_GREEN);
        }
    }

    public function convertModel($oldSettings): bool|array|null
    {
        $oldType = $oldSettings['type'] ?? null;

        // Return null for an empty field, false for when unable to find matching new type
        if (!$oldType) {
            return null;
        }

        $linkTypeClass = $this->getLinkType($oldType);

        if (!$linkTypeClass) {
            return false;
        }

        $link = new $linkTypeClass();
        $link->handle = 'default-' . StringHelper::toKebabCase($linkTypeClass);
        $link->linkValue = $oldSettings['linkedUrl'] ?? null;

        $advanced = Json::decode($oldSettings['payload']);
        $link->ariaLabel = $advanced['ariaLabel'] ?? null;
        $link->linkText = $advanced['customText'] ?? null;
        $link->linkTitle = $advanced['title'] ?? null;
        $link->newWindow = ($advanced['target'] ?? '') === '_blank';

        if ($link instanceof ElementLink) {
            $link->linkSiteId = $oldSettings['linkedSiteId'] ?? null;
            $link->linkValue = $oldSettings['linkedId'] ?? null;
        }

        return [$link->getSerializedValues()];
    }

    public function migrateBlockField($matrixField, $newField): void
    {
        $blockTypes = $matrixField->getBlockTypes();

        foreach ($blockTypes as $blockType) {
            if ($fieldLayout = $blockType->getFieldLayout()) {
                $tabs = $fieldLayout->getTabs();

                foreach ($tabs as $tab) {
                    $tabElements = $tab->getElements();

                    foreach ($tabElements as $tabElement) {
                        if ($tabElement instanceof CustomField) {
                            $tabField = $tabElement->getField();

                            // Using string checks fixes an issue when converting multiple fields in a single Matrix field
                            if ((string)$tabField->id === (string)$newField->id) {
                                $tabElement->setField($newField);
                            }
                        }
                    }

                    $tab->setElements($tabElements);
                }

                $fieldLayout->setTabs($tabs);

                $blockType->setFieldLayout($fieldLayout);
            }
        }

        $matrixField->setBlockTypes($blockTypes);
    }
}
