<?php

declare(strict_types=1);

namespace PhpMyAdmin\Controllers\Table;

use PhpMyAdmin\Config;
use PhpMyAdmin\Container\ContainerBuilder;
use PhpMyAdmin\Controllers\AbstractController;
use PhpMyAdmin\Current;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\DbTableExists;
use PhpMyAdmin\Html\Generator;
use PhpMyAdmin\Http\ServerRequest;
use PhpMyAdmin\Identifiers\DatabaseName;
use PhpMyAdmin\Identifiers\TableName;
use PhpMyAdmin\Index;
use PhpMyAdmin\Message;
use PhpMyAdmin\ResponseRenderer;
use PhpMyAdmin\Table\Indexes;
use PhpMyAdmin\Template;
use PhpMyAdmin\Url;
use PhpMyAdmin\Util;

use function __;
use function is_array;

final class IndexRenameController extends AbstractController
{
    public function __construct(
        ResponseRenderer $response,
        Template $template,
        private DatabaseInterface $dbi,
        private Indexes $indexes,
        private readonly DbTableExists $dbTableExists,
    ) {
        parent::__construct($response, $template);
    }

    public function __invoke(ServerRequest $request): void
    {
        $GLOBALS['urlParams'] ??= null;
        $GLOBALS['errorUrl'] ??= null;

        if (! isset($_POST['create_edit_table'])) {
            if (! $this->checkParameters(['db', 'table'])) {
                return;
            }

            $GLOBALS['urlParams'] = ['db' => Current::$database, 'table' => Current::$table];
            $GLOBALS['errorUrl'] = Util::getScriptNameForOption(
                Config::getInstance()->settings['DefaultTabTable'],
                'table',
            );
            $GLOBALS['errorUrl'] .= Url::getCommon($GLOBALS['urlParams'], '&');

            $databaseName = DatabaseName::tryFrom($request->getParam('db'));
            if ($databaseName === null || ! $this->dbTableExists->selectDatabase($databaseName)) {
                if ($request->isAjax()) {
                    $this->response->setRequestStatus(false);
                    $this->response->addJSON('message', Message::error(__('No databases selected.')));

                    return;
                }

                $this->redirect('/', ['reload' => true, 'message' => __('No databases selected.')]);

                return;
            }

            $tableName = TableName::tryFrom($request->getParam('table'));
            if ($tableName === null || ! $this->dbTableExists->hasTable($databaseName, $tableName)) {
                if ($request->isAjax()) {
                    $this->response->setRequestStatus(false);
                    $this->response->addJSON('message', Message::error(__('No table selected.')));

                    return;
                }

                $this->redirect('/', ['reload' => true, 'message' => __('No table selected.')]);

                return;
            }
        }

        if (isset($_POST['index'])) {
            if (is_array($_POST['index'])) {
                // coming already from form
                $oldIndex = is_array($_POST['old_index']) ? $_POST['old_index']['Key_name'] : $_POST['old_index'];
                $index = clone $this->dbi->getTable(Current::$database, Current::$table)->getIndex($oldIndex);
                $index->setName($_POST['index']['Key_name']);
            } else {
                $index = $this->dbi->getTable(Current::$database, Current::$table)->getIndex($_POST['index']);
            }
        } else {
            $index = new Index();
        }

        if (isset($_POST['do_save_data'])) {
            $oldIndexName = $request->getParsedBodyParam('old_index', '');
            $previewSql = $request->hasBodyParam('preview_sql');

            $sqlResult = $this->indexes->doSaveData(
                $index,
                true,
                Current::$database,
                Current::$table,
                $previewSql,
                $oldIndexName,
            );

            // If there is a request for SQL previewing.
            if ($previewSql) {
                $this->response->addJSON(
                    'sql_data',
                    $this->template->render('preview_sql', ['query_data' => $sqlResult]),
                );

                return;
            }

            if ($sqlResult instanceof Message) {
                $this->response->setRequestStatus(false);
                $this->response->addJSON('message', $sqlResult);

                return;
            }

            if ($request->isAjax()) {
                $message = Message::success(
                    __('Table %1$s has been altered successfully.'),
                );
                $message->addParam(Current::$table);
                $this->response->addJSON(
                    'message',
                    Generator::getMessage($message, $sqlResult, 'success'),
                );

                $indexes = Index::getFromTable($this->dbi, Current::$table, Current::$database);
                $indexesDuplicates = Index::findDuplicates(Current::$table, Current::$database);

                $this->response->addJSON(
                    'index_table',
                    $this->template->render('indexes', [
                        'url_params' => ['db' => Current::$database, 'table' => Current::$table],
                        'indexes' => $indexes,
                        'indexes_duplicates' => $indexesDuplicates,
                    ]),
                );

                return;
            }

            /** @var StructureController $controller */
            $controller = ContainerBuilder::getContainer()->get(StructureController::class);
            $controller($request);

            return;
        }

        $this->displayRenameForm($index);
    }

    /**
     * Display the rename form to rename an index
     *
     * @param Index $index An Index instance.
     */
    private function displayRenameForm(Index $index): void
    {
        $this->dbi->selectDb(Current::$database);

        $formParams = ['db' => Current::$database, 'table' => Current::$table];

        if (isset($_POST['old_index'])) {
            $formParams['old_index'] = $_POST['old_index'];
        } elseif (isset($_POST['index'])) {
            $formParams['old_index'] = $_POST['index'];
        }

        $this->render('table/index_rename_form', ['index' => $index, 'form_params' => $formParams]);
    }
}
