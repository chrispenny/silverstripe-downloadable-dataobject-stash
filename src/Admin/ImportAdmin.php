<?php

namespace ChrisPenny\DataObjectStash\Admin;

use ChrisPenny\DataObjectStash\Admin\Form\ExportButton;
use ChrisPenny\DataObjectStash\Admin\Form\ImportButton;
use ChrisPenny\DataObjectStash\Admin\Model\ImportHistory;
use ChrisPenny\DataObjectStash\Service\DataObjectService;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FileField;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldImportButton;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use Symbiote\GridFieldExtensions\GridFieldAddExistingSearchButton;
use Throwable;

class ImportAdmin extends ModelAdmin implements PermissionProvider
{

    public const PERMISSION_IMPORT_ADMIN = 'DataObjectStash_Import_Admin';
    public const PERMISSION_IMPORT = 'DataObjectStash_Import';
    public const PERMISSION_EXPORT = 'DataObjectStash_Export';

    private static string $url_segment = 'fixture-import';

    private static string $menu_title = 'Fixture Import';

    private static string $required_permission_codes = self::PERMISSION_IMPORT_ADMIN;

    private static array $managed_models = [
        'import-history' => [
            'dataClass' => ImportHistory::class,
            'title' => 'Import History',
        ],
        'bulk-export' => [
            'dataClass' => SiteTree::class,
            'title' => 'Bulk Export',
        ],
    ];

    private static array $allowed_actions = [
        'ImportForm',
    ];

    private static array $url_handlers = [
        'import' => 'import',
    ];

    public function getEditForm($id = null, $fields = null) // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $form = parent::getEditForm($id, $fields);

        $this->updateImportInterface($form);
        $this->updateExportInterface($form);

        return $form;
    }

    public function getList(): DataList
    {
        $list = parent::getList();

        if ($this->modelTab === 'bulk-export') {
            return $list->filter(['BulkFixtureExport' => 1]);
        }

        return $list;
    }

    public function ImportForm() // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $fields = FieldList::create([
            FileField::create('_YmlFile', 'Upload yml file')
                ->setAllowedExtensions(['yml']),
        ]);

        $actions = FieldList::create([
            FormAction::create('import', 'Import from yaml')
                ->addExtraClass('btn btn-primary'),
        ]);

        $form = new Form(
            $this,
            'ImportForm',
            $fields,
            $actions
        );
        $form->setFormAction(Controller::join_links($this->Link(), 'ImportForm'));

        return $form;
    }

    public function import($data, $form, $request) // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $fileName = $_FILES['_YmlFile']['tmp_name'] ?? null;

        // File wasn't properly uploaded, show a reminder to the user
        if (!$fileName || !file_get_contents($fileName)) {
            $form->sessionMessage('Please browse for a yaml file to import');
            $this->redirectBack();

            return false;
        }

        /*
         * Populate uses DB::alterationMessage() to display messages when the dev task is run.
         * This function echos the message immediately so we need to suppress it here otherwise the messages will
         * appear briefly when the page reloads
         */
        DB::quiet();

        try {
            $service = new DataObjectService();
            $service->importFromStream($fileName);
        } catch (Throwable $e) {
            // database exceptions are especially ugly so it is best to simplify this for the CMS users experience
            $message = $e instanceof DatabaseException
                ? 'A Database error has occurred. This may be caused by referencing a non existant DataObject or Field.'
                    . ' Some of the Objects defined in this file may still have been imported.'
                : $e->getMessage();

            $form->sessionMessage($message);
            $this->redirectBack();

            return false;
        }

        DB::quiet(false);

        $importHistory = ImportHistory::create();
        $importHistory->Filename = $_FILES['_YmlFile']['name'];
        $importHistory->write();

        $form->sessionMessage('Successfully imported fixture', ValidationResult::TYPE_GOOD);
        $this->redirectBack();

        return true;
    }

    public function providePermissions() // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        return [
            self::PERMISSION_EXPORT => [
                'name' => _t('DataObjectToStash.PERMISSION_EXPORT', 'Export'),
                'category' => _t('DataObjectToStash.PERMISSION_CATEGORY', 'DataObject to Fixture'),
                'help' => _t('DataObjectToStash.PERMISSION_HELP', 'Allow users to export pages to yaml files'),
                'sort' => 0,
            ],
            self::PERMISSION_IMPORT => [
                'name' => _t('DataObjectToStash.PERMISSION_IMPORT', 'Import'),
                'category' => _t('DataObjectToStash.PERMISSION_CATEGORY', 'DataObject to Fixture'),
                'help' => _t('DataObjectToStash.PERMISSION_HELP', 'Allow users to import pages from yaml files'),
                'sort' => 1,
            ],
        ];
    }

    protected function updateImportInterface(Form $form): void
    {
        /** @var GridField $importHistoryGridField */
        $importHistoryGridField = $form->Fields()->fieldByName('import-history');

        if (!$importHistoryGridField) {
            return;
        }

        $config = $importHistoryGridField->getConfig();

        // Remove default Components
        $config->removeComponentsByType(GridFieldImportButton::class);
        $config->removeComponentsByType(GridFieldAddNewButton::class);
        $config->removeComponentsByType(GridFieldAddExistingSearchButton::class);
        $config->removeComponentsByType(GridFieldPrintButton::class);
        $config->removeComponentsByType(GridFieldExportButton::class);

        if (!Permission::check(ImportAdmin::PERMISSION_IMPORT)) {
            return;
        }

        // Add our own ImportButton (that contains the correct naming)
        $config->addComponent(
            ImportButton::create('buttons-before-left')
                ->setImportForm($this->ImportForm())
                ->setModalTitle('Import from Yaml')
        );
    }

    protected function updateExportInterface(Form $form): void
    {
        /** @var GridField $bulkExportGridField */
        $bulkExportGridField = $form->Fields()->fieldByName('bulk-export');

        if (!$bulkExportGridField) {
            return;
        }

        $config = $bulkExportGridField->getConfig();

        // Remove default Components
        $config->removeComponentsByType(GridFieldImportButton::class);
        $config->removeComponentsByType(GridFieldAddNewButton::class);
        $config->removeComponentsByType(GridFieldAddExistingSearchButton::class);
        $config->removeComponentsByType(GridFieldPrintButton::class);
        $config->removeComponentsByType(GridFieldExportButton::class);

        if (!Permission::check(ImportAdmin::PERMISSION_EXPORT)) {
            return;
        }

        // Add our own ImportButton (that contains the correct naming)
        $config->addComponent(
            new ExportButton('buttons-before-left')
        );
    }

}
