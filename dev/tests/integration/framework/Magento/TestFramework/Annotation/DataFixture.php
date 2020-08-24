<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\TestFramework\Annotation;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Event\Param\Transaction;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Implementation of the @magentoDataFixture DocBlock annotation.
 */
class DataFixture extends AbstractDataFixture
{
    public const ANNOTATION = 'magentoDataFixture';

    /**
     * This variable was created to keep initial data cached
     *
     * @var array
     */
    private $dbTableState = [];

    /**
     * @var string[]
     */
    private $dbStateTables = [
        'catalog_product_entity',
        'eav_attribute',
        'catalog_category_entity',
        'eav_attribute_set',
        'store',
        'store_website',
        'url_rewrite'
    ];

    /**
     * Pull data from specific table
     *
     * @param string $table
     * @return array
     */
    private function pullDbState(string $table): array
    {
        $resource = ObjectManager::getInstance()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $select = $connection->select()->from($table);
        return $connection->fetchAll($select);
    }

    /**
     * Handler for 'startTestTransactionRequest' event
     *
     * @param TestCase $test
     * @param Transaction $param
     * @return void
     */
    public function startTestTransactionRequest(TestCase $test, Transaction $param): void
    {
        $fixtures = $this->_getFixtures($test);
        /* Start transaction before applying first fixture to be able to revert them all further */
        if ($fixtures) {
            if ($this->getDbIsolationState($test) !== ['disabled']) {
                $param->requestTransactionStart();
            } else {
                $this->saveDbStateBeforeTestRun($test);
                $this->_applyFixtures($fixtures);
            }
        }
    }

    /**
     * At the first run before test we need to warm up attributes list to have native attributes list
     *
     * @param TestCase $test
     * @return void
     */
    private function saveDbStateBeforeTestRun(TestCase $test): void
    {
        try {
            if (empty($this->dbTableState)) {
                foreach ($this->dbStateTables as $table) {
                    $this->dbTableState[$table] = $this->pullDbState($table);
                }
            }
        } catch (\Throwable $e) {
            $test->getTestResultObject()->addFailure(
                $test,
                new AssertionFailedError(
                    $e->getMessage()
                ),
                0
            );
        }
    }

    /**
     * Compare data difference for m-dimensional array
     *
     * @param array $dataBefore
     * @param array $dataAfter
     * @return bool
     */
    private function dataDiff(array $dataBefore, array $dataAfter): array
    {
        $diff = [];
        if (count($dataBefore) !== count($dataAfter)) {
            $diff = array_slice($dataAfter, count($dataBefore));
        }

        return $diff;
    }

    /**
     * Handler for 'endTestNeedTransactionRollback' event
     *
     * @param TestCase $test
     * @param Transaction $param
     * @return void
     */
    public function endTestTransactionRequest(TestCase $test, Transaction $param): void
    {
        /* Isolate other tests from test-specific fixtures */
        if ($this->_appliedFixtures && $this->_getFixtures($test)) {
            if ($this->getDbIsolationState($test) !== ['disabled']) {
                $param->requestTransactionRollback();
            } else {
                $this->_revertFixtures();
                $this->checkResidualData($test);
            }
        }
    }

    /**
     * Compare data from
     *
     * @param TestCase $test
     */
    private function checkResidualData(\PHPUnit\Framework\TestCase $test)
    {
        $isolationProblem = [];
        foreach ($this->dbTableState as $table => $isolationData) {
            try {
                $diff = $this->dataDiff(
                    $isolationData,
                    $this->pullDbState($table)
                );

                if (!empty($diff)) {
                    $isolationProblem[$table] = $diff;
                }
            } catch (\Exception $e) {
                //ResourceConnection could be not specified in some specific tests that are not working with DB
                //We need to ignore it
            }
        }

        if (!empty($isolationProblem)) {
            $test->getTestResultObject()->addFailure(
                $test,
                new AssertionFailedError(
                    "There was a problem with isolation: " . var_export($isolationProblem, true)
                ),
                0
            );
        }
    }

    /**
     * Handler for 'startTransaction' event
     *
     * @param TestCase $test
     * @return void
     */
    public function startTransaction(TestCase $test): void
    {
        $this->_applyFixtures($this->_getFixtures($test));
    }

    /**
     * Handler for 'rollbackTransaction' event
     *
     * @return void
     */
    public function rollbackTransaction(): void
    {
        $this->_revertFixtures();
    }

    /**
     * @inheritdoc
     */
    protected function getAnnotation(): string
    {
        return self::ANNOTATION;
    }
}
