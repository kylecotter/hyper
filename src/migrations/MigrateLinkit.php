<?php
namespace verbb\hyper\migrations;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

use presseddigital\linkit\fields\LinkitField;
use presseddigital\linkit\models\Asset;
use presseddigital\linkit\models\Category;
use presseddigital\linkit\models\Email;
use presseddigital\linkit\models\Entry;
use presseddigital\linkit\models\Facebook;
use presseddigital\linkit\models\Instagram;
use presseddigital\linkit\models\LinkedIn;
use presseddigital\linkit\models\Phone;
use presseddigital\linkit\models\Twitter;
use presseddigital\linkit\models\Url;
use presseddigital\linkit\models\User;

class MigrateLinkit extends PluginMigration
{
    // Properties
    // =========================================================================

    public array $typeMap = [
        Asset::class => linkTypes\Asset::class,
        Category::class => linkTypes\Category::class,
        Email::class => linkTypes\Email::class,
        Entry::class => linkTypes\Entry::class,
        Phone::class => linkTypes\Phone::class,
        Url::class => linkTypes\Url::class,
        Twitter::class => linkTypes\Url::class,
        Facebook::class => linkTypes\Url::class,
        Instagram::class => linkTypes\Url::class,
        LinkedIn::class => linkTypes\Url::class,
        User::class => linkTypes\User::class,
    ];

    public string $oldFieldTypeClass = LinkitField::class;


    // Public Methods
    // =========================================================================

    public function processFieldSettings(): void
    {
        foreach ($this->fields as $field) {
            $this->stdout("Preparing to migrate field “{$field['handle']}” ({$field['uid']}).");

            $settings = Json::decode($field['settings']);
            $allowCustomText = $settings['allowCustomText'] ?? true;

            $types = [];

            foreach (($settings['types'] ?? []) as $key => $type) {
                $linkTypeClass = $this->getLinkType($key);

                if (!$linkTypeClass) {
                    continue;
                }

                $linkType = new $linkTypeClass();
                $linkType->label = $linkType::displayName();
                $linkType->handle = 'default-' . StringHelper::toKebabCase($linkTypeClass);
                $linkType->enabled = $type['enabled'] ?? false;
                $linkType->linkText = $type['customLabel'] ?? null;

                if ($linkType instanceof ElementLink) {
                    $linkType->sources = $type['sources'] ?? '*';
                    $linkType->selectionLabel = $type['customSelectionLabel'] ?? null;
                } else {
                    $linkType->placeholder = $type['customPlaceholder'] ?? null;
                }

                $fieldLayout = self::getDefaultFieldLayout($allowCustomText);
                $linkType->layoutUid = StringHelper::UUID();
                $linkType->layoutConfig = $fieldLayout->getConfig();

                $types[] = $linkType->getSettingsConfig();
            }

            // Create a new Hyper field instance to have the settings validated correctly
            $newFieldConfig = $field;
            unset($newFieldConfig['type'], $newFieldConfig['settings']);

            $newFieldConfig['newWindow'] = $settings['allowTarget'] ?? false;
            $newFieldConfig['linkTypes'] = $types;

            $newField = new HyperField($newFieldConfig);

            if (!$newField->validate()) {
                $this->stdout(Json::encode($newField->getErrors()) . PHP_EOL, Console::FG_RED);

                continue;
            }

            $this->prepLinkTypes($newField);

            Db::update('{{%fields}}', ['type' => HyperField::class, 'settings' => Json::encode($newField->settings)], ['id' => $field['id']], [], true, $this->db);

            $this->stdout("    > Field “{$field['handle']}” migrated." . PHP_EOL, Console::FG_GREEN);
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
        $link->linkValue = $oldSettings['value'] ?? null;
        $link->linkText = $oldSettings['customText'] ?? null;
        $link->newWindow = $oldSettings['target'] ?? false;

        return [$link->getSerializedValues()];
    }
}
