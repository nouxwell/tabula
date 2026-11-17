<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Grid;

use Nouxwell\Tabula\Exception\GridException;
use Nouxwell\Tabula\Grid\GridIssue;
use Nouxwell\Tabula\Grid\GridIssueCode;
use Nouxwell\Tabula\Grid\GridSchema;
use Nouxwell\Tabula\Grid\RowChecker;
use Nouxwell\Tabula\Grid\Rule;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rows a grid sends are its own values, never a user's text: decimal text with a point, ISO
 * dates, true and false. The checker reads them as exactly that, and reports in the grid's terms.
 */
#[CoversClass(RowChecker::class)]
#[CoversClass(GridIssue::class)]
final class RowCheckerTest extends TestCase
{
    private static function grid(): GridSchema
    {
        $schema = Schema::make('product.price_update')->fields(
            Field::integer('productId')->required(),
            Field::string('sku'),
            Field::integer('quantity'),
            Field::money('newPrice')->required(),
            Field::options('currency', ['TRY' => 'TRY', 'EUR' => 'EUR']),
            Field::enum('status', Status::class),
            Field::date('validFrom'),
            Field::dateTime('publishAt'),
            Field::bool('active'),
            Field::string('note'),
        );

        return GridSchema::of($schema, 1)
            ->lookup('productId', 'product')
            ->rules('productId', Rule::unique())
            ->rules('newPrice', Rule::min('0'), Rule::max('1000'))
            ->rules('note', Rule::minLength(3), Rule::maxLength(5))
            ->notSubmitted('sku');
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<string> "field:code" for each issue
     */
    private static function codes(array $values): array
    {
        $issues = (new Tabula())->checkRows(self::grid(), [
            ['rowId' => 'r1', 'values' => ['productId' => 7, 'newPrice' => '10', ...$values]],
        ]);

        return array_map(static fn (GridIssue $issue): string => $issue->field.':'.$issue->code->value, $issues);
    }

    #[Test]
    public function itFindsNothingInRowsThatKeepTheRules(): void
    {
        self::assertSame([], self::codes([
            'quantity' => 12,
            'newPrice' => '999.99',
            'currency' => 'EUR',
            'status' => 'closed',
            'validFrom' => '2026-12-31',
            'publishAt' => '2026-12-31T09:30:00',
            'active' => false,
            'note' => '📦📦📦',
        ]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function wrongValues(): iterable
    {
        yield 'an empty required field' => [['newPrice' => null], ['newPrice:required']];
        yield 'text in a number' => [['newPrice' => 'on iki'], ['newPrice:not_a_number']];
        yield 'a Turkish number, which the grid never sends' => [['newPrice' => '1.234,5'], ['newPrice:not_a_number']];
        yield 'a fraction in an integer' => [['quantity' => '1.5'], ['quantity:not_an_integer']];
        yield 'a word in an integer' => [['quantity' => 'iki'], ['quantity:not_a_number']];
        yield 'a fraction sent as a number' => [['quantity' => 1.5], ['quantity:not_an_integer']];
        yield 'a whole number past what PHP holds' => [['quantity' => '9223372036854775808'], ['quantity:not_an_integer']];
        yield 'a whole number JavaScript no longer holds exactly' => [['quantity' => 2.0 ** 53], ['quantity:not_an_integer']];
        yield 'a day that does not exist' => [['validFrom' => '2026-02-30'], ['validFrom:not_a_date']];
        yield 'a date as a user writes it' => [['validFrom' => '31.12.2026'], ['validFrom:not_a_date']];
        yield 'an hour that does not exist' => [['publishAt' => '2026-12-31T24:00:00'], ['publishAt:not_a_date']];
        yield 'a yes word rather than true' => [['active' => 'evet'], ['active:not_a_boolean']];
        yield 'an option not offered' => [['currency' => 'GBP'], ['currency:not_an_option']];
        yield 'an enum case not offered' => [['status' => 'Open'], ['status:not_an_option']];
        yield 'a lookup never matched to a record' => [['productId' => ['label' => 'Termos']], ['productId:not_matched']];
        yield 'below the minimum' => [['newPrice' => '-0.01'], ['newPrice:min_value']];
        yield 'above the maximum, compared as decimals' => [['newPrice' => '1000.000001'], ['newPrice:max_value']];
        yield 'too short' => [['note' => 'ab'], ['note:min_length']];
        yield 'too long, in characters a reader counts' => [['note' => '📦📦📦📦📦📦'], ['note:max_length']];
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $expected
     */
    #[Test]
    #[DataProvider('wrongValues')]
    public function itReportsAValueThatBreaksTheSchemaOrItsRules(array $values, array $expected): void
    {
        self::assertSame($expected, self::codes($values));
    }

    #[Test]
    public function itWordsTheIssueAsTheBrowserDoesAndGivesTheParams(): void
    {
        $issues = (new Tabula())->checkRows(self::grid(), [['rowId' => 'r1', 'values' => ['productId' => 7, 'newPrice' => '-5']]]);

        self::assertCount(1, $issues);
        self::assertSame([
            'scope' => 'cell',
            'rowId' => 'r1',
            'field' => 'newPrice',
            'targets' => [],
            'code' => 'min_value',
            'severity' => 'error',
            'message' => '-5 is below the minimum of 0.',
            'params' => ['field' => 'newPrice', 'type' => 'money', 'value' => '-5', 'min' => '0'],
        ], $issues[0]->toArray());
    }

    #[Test]
    public function itMarksEveryRowHoldingTheSameRecordAndPointsEachAtTheOthers(): void
    {
        $issues = (new RowChecker())->check(self::grid(), [
            ['rowId' => 'a', 'values' => ['productId' => 7, 'newPrice' => '1']],
            ['rowId' => 'b', 'values' => ['productId' => 8, 'newPrice' => '1']],
            ['rowId' => 'c', 'values' => ['productId' => 7, 'newPrice' => '1']],
            // The id 7 and the id "7" are two records, as the browser tells them.
            ['rowId' => 'd', 'values' => ['productId' => '7', 'newPrice' => '1']],
        ]);

        self::assertSame(['a', 'c'], array_map(static fn (GridIssue $issue): string => $issue->rowId, $issues));
        self::assertSame(GridIssueCode::NotUnique, $issues[0]->code);
        self::assertSame(['field' => 'productId', 'value' => '7', 'count' => '2'], $issues[0]->params);
        self::assertSame([['rowId' => 'c', 'field' => 'productId']], $issues[0]->targets);
    }

    #[Test]
    public function itComparesOnlyValuesWithNothingElseWrong(): void
    {
        $grid = GridSchema::of(Schema::make('codes')->fields(Field::string('code')), 1)
            ->rules('code', Rule::maxLength(4), Rule::unique());

        $issues = (new RowChecker())->check($grid, [
            // Too long on both rows: that is all they are told, not that they also match.
            ['rowId' => 'a', 'values' => ['code' => 'ABCDEF']],
            ['rowId' => 'b', 'values' => ['code' => 'ABCDEF']],
            ['rowId' => 'c', 'values' => ['code' => 'AB']],
            ['rowId' => 'd', 'values' => ['code' => 'AB']],
        ]);

        self::assertSame(['a.code:max_length', 'b.code:max_length', 'c.code:not_unique', 'd.code:not_unique'], array_map(
            static fn (GridIssue $issue): string => $issue->rowId.'.'.$issue->field.':'.$issue->code->value,
            $issues,
        ));
    }

    #[Test]
    public function itLeavesOutFieldsTheGridDoesNotSend(): void
    {
        self::assertSame([], self::codes(['sku' => ['not', 'a', 'string']]));
    }

    #[Test]
    public function itListsIssuesByRowAndThenByField(): void
    {
        $issues = (new RowChecker())->check(self::grid(), [
            ['rowId' => 'a', 'values' => ['productId' => 1, 'newPrice' => 'x', 'quantity' => 'y']],
            ['rowId' => 'b', 'values' => ['productId' => null, 'newPrice' => '1']],
        ]);

        self::assertSame(
            ['a.quantity', 'a.newPrice', 'b.productId'],
            array_map(static fn (GridIssue $issue): string => $issue->rowId.'.'.$issue->field, $issues),
        );
    }

    #[Test]
    public function itRefusesRowsNotInTheGridsShape(): void
    {
        $this->expectException(GridException::class);
        $this->expectExceptionMessage('Row 1 has no rowId; every row the grid sends carries one.');

        (new RowChecker())->check(self::grid(), [['rowId' => 'a', 'values' => []], ['values' => []]]);
    }

    #[Test]
    public function itRefusesTheSameRowIdTwice(): void
    {
        $this->expectException(GridException::class);

        (new RowChecker())->check(self::grid(), [['rowId' => 'a', 'values' => []], ['rowId' => 'a', 'values' => []]]);
    }
}
