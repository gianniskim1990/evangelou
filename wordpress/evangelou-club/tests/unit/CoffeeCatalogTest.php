<?php
use PHPUnit\Framework\TestCase;

final class CoffeeCatalogTest extends TestCase {
    private static function fixture(): EVC_Coffee_Catalog {
        return new EVC_Coffee_Catalog(require dirname(__DIR__) . '/fixtures/coffee-catalog.php');
    }

    public function test_fixture_codes_are_accepted(): void {
        $catalog = self::fixture();
        foreach (array('espresso', 'freddo_espresso', 'cappuccino', 'greek_coffee') as $code) {
            $this->assertTrue($catalog->contains($code), $code);
        }
        $this->assertSame('TEST Espresso', $catalog->label('espresso'));
    }

    /** @return array<string,array{mixed}> */
    public static function rejected_codes(): array {
        return array(
            'unknown but well-formed' => array('mocha_latte'),
            'uppercase' => array('ESPRESSO'),
            'mixed case' => array('Espresso'),
            'leading space' => array(' espresso'),
            'trailing space' => array('espresso '),
            'trailing newline' => array("espresso\n"),
            'hyphen' => array('freddo-espresso'),
            'empty' => array(''),
            'single char' => array('e'),
            'too long' => array(str_repeat('a', 33)),
            'sql injection' => array("espresso' OR '1'='1"),
            'null' => array(null),
            'integer' => array(1),
            'array' => array(array('espresso')),
            'bool' => array(true),
            'greek label instead of code' => array('Εσπρέσο'),
        );
    }

    /** @dataProvider rejected_codes */
    public function test_rejects_untrusted_codes($code): void {
        $this->assertFalse(self::fixture()->contains($code));
    }

    public function test_empty_catalog_rejects_everything(): void {
        $this->assertFalse((new EVC_Coffee_Catalog(array()))->contains('espresso'));
    }

    public function test_constructor_rejects_malformed_codes(): void {
        $this->expectException(InvalidArgumentException::class);
        new EVC_Coffee_Catalog(array('Bad Code' => 'x'));
    }

    public function test_constructor_rejects_empty_labels(): void {
        $this->expectException(InvalidArgumentException::class);
        new EVC_Coffee_Catalog(array('espresso' => '  '));
    }
}
