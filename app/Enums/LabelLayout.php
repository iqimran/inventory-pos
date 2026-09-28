<?php

namespace App\Enums;

/**
 * Barcode label stock. Roll layouts print one label per page (thermal label printers);
 * the A4 layout prints a grid of labels on a sticker sheet.
 */
enum LabelLayout: string
{
    case Roll38x25 = 'ROLL_38X25';
    case Roll50x30 = 'ROLL_50X30';
    case A4Sheet = 'A4_3X7';

    public function label(): string
    {
        return match ($this) {
            self::Roll38x25 => 'Label roll 38 × 25 mm',
            self::Roll50x30 => 'Label roll 50 × 30 mm',
            self::A4Sheet => 'A4 sticker sheet (3 × 7, 63.5 × 38.1 mm)',
        };
    }

    /**
     * Print geometry in millimetres, used by the print template.
     *
     * @return array{page_width: float, page_height: float, label_width: float, label_height: float, columns: int, rows: int, margin_top: float, margin_left: float, gap_x: float, gap_y: float}
     */
    public function geometry(): array
    {
        return match ($this) {
            self::Roll38x25 => ['page_width' => 38, 'page_height' => 25, 'label_width' => 38, 'label_height' => 25, 'columns' => 1, 'rows' => 1, 'margin_top' => 0, 'margin_left' => 0, 'gap_x' => 0, 'gap_y' => 0],
            self::Roll50x30 => ['page_width' => 50, 'page_height' => 30, 'label_width' => 50, 'label_height' => 30, 'columns' => 1, 'rows' => 1, 'margin_top' => 0, 'margin_left' => 0, 'gap_x' => 0, 'gap_y' => 0],
            self::A4Sheet => ['page_width' => 210, 'page_height' => 297, 'label_width' => 63.5, 'label_height' => 38.1, 'columns' => 3, 'rows' => 7, 'margin_top' => 15.15, 'margin_left' => 7.25, 'gap_x' => 2.5, 'gap_y' => 0],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $layout) => ['value' => $layout->value, 'label' => $layout->label()], self::cases());
    }
}
