<?php
use PHPUnit\Framework\TestCase;

/** Task 1D-G store contract suite against the TEST-ONLY in-memory store. */
final class InMemoryProvenanceStoreContractTest extends TestCase {
    use EVC_Provenance_Store_Contract;

    /** @var EVC_In_Memory_Provenance_Store */
    private $store;

    protected function setUp(): void {
        $this->store = new EVC_In_Memory_Provenance_Store();
    }

    protected function contract_store(): EVC_Provenance_Store {
        return $this->store;
    }
}
