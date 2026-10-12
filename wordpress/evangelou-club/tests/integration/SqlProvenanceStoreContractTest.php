<?php

/** Task 1D-G store contract suite against EVC_Sql_Provenance_Store on a real, freshly migrated database. */
final class SqlProvenanceStoreContractTest extends EVC_Db_Test_Case {
    use EVC_Provenance_Store_Contract;

    /** @var EVC_Sql_Provenance_Store|null */
    private $store;

    protected function contract_store(): EVC_Provenance_Store {
        if ($this->store === null) {
            $this->store = EVC_Provenance_Test_Fixture::sql_store($this->database->config());
        }
        return $this->store;
    }

    protected function tearDown(): void {
        $this->store = null;
        parent::tearDown();
    }
}
