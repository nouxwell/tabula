<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Grid;

/**
 * What is wrong with one cell of the rows a grid sent, bound to the row by the id the grid gave it
 * and to the field by its key, never to a row number: the grid shows it on the cell it is about,
 * wherever that row has moved since.
 *
 * `$message` is English, for a developer or as a last resort; the grid words the issue from the
 * code and params in the user's own language.
 */
final readonly class GridIssue
{
    /**
     * @param array<string, string>                     $params
     * @param list<array{rowId: string, field: string}> $targets other cells the issue is about, such
     *                                                           as the rows holding the same value
     */
    public function __construct(
        public string $rowId,
        public string $field,
        public GridIssueCode $code,
        public string $message,
        public array $params,
        public array $targets = [],
    ) {
    }

    /**
     * In the shape the grid reads an issue (`IssueInput`): ready to `json_encode` into the answer to
     * a pre-flight check, or into a row's result.
     *
     * @return array{
     *     scope: 'cell',
     *     rowId: string,
     *     field: string,
     *     targets: list<array{rowId: string, field: string}>,
     *     code: string,
     *     severity: 'error',
     *     message: string,
     *     params: array<string, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'scope' => 'cell',
            'rowId' => $this->rowId,
            'field' => $this->field,
            'targets' => $this->targets,
            'code' => $this->code->value,
            'severity' => 'error',
            'message' => $this->message,
            'params' => $this->params,
        ];
    }
}
