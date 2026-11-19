<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;

/**
 * How milling weight is taken (paddy fed into a batch, rice and by-products coming off it), next to the net kg:
 *   weight on the scale − bags × bardana (empty bag) − other deduction (moisture, dust …) = net kg booked.
 * Typing them fills the net kg (which may still be typed directly); the breakdown is kept in the log's note.
 */
final class MillingWeights
{
    /** @return list<Component> */
    public static function fields(): array
    {
        $recalc = fn (Get $get, Set $set) => ($net = self::net($get('scale_kg'), $get('bags'), $get('bardana_kg'), $get('other_deduction_kg'))) !== null
            ? $set('quantity_kg', $net) : null;

        return [
            TextInput::make('bags')->label(__('Bags'))->integer()->minValue(0)->live(onBlur: true)->afterStateUpdated($recalc),
            TextInput::make('scale_kg')->label(__('Weight on the scale (kg)'))->regex(Units::DECIMAL_3)->live(onBlur: true)->afterStateUpdated($recalc),
            TextInput::make('bardana_kg')->label(__('Bardana per bag (kg)'))->regex(Units::DECIMAL_3)->placeholder('0.5')->live(onBlur: true)->afterStateUpdated($recalc),
            TextInput::make('other_deduction_kg')->label(__('Other deduction (kg)'))->regex(Units::DECIMAL_3)->live(onBlur: true)->afterStateUpdated($recalc)
                ->helperText(__('Moisture, dust, sweepings.')),
        ];
    }

    /** gross − bags × bardana − other, or null when no scale weight is typed. */
    public static function net(mixed $scale, mixed $bags, mixed $bardana, mixed $other): ?string
    {
        $num = fn (mixed $v) => preg_match('/^\d{1,12}(\.\d{1,3})?$/', trim((string) $v)) ? trim((string) $v) : '0';
        if ($num($scale) === '0') {
            return null;
        }
        $net = bcsub(bcsub($num($scale), bcmul((string) (int) $bags, $num($bardana), 3), 3), $num($other), 3);

        return bccomp($net, '0', 3) > 0 ? $net : null;
    }

    /**
     * The breakdown added to the log's note, e.g. "Weighed 10,250 kg, 200 bags × 0.5 kg bardana, 50 kg other → 10,100 kg".
     *
     * @param  array<string, mixed>  $data
     */
    public static function note(array $data, ?string $note): ?string
    {
        if (blank($data['scale_kg'] ?? null)) {
            return $note;
        }
        $parts = ['Weighed '.$data['scale_kg'].' kg'];
        if (filled($data['bags'] ?? null)) {
            $parts[] = $data['bags'].' bags'.(filled($data['bardana_kg'] ?? null) ? ' × '.$data['bardana_kg'].' kg bardana' : '');
        }
        if (filled($data['other_deduction_kg'] ?? null)) {
            $parts[] = $data['other_deduction_kg'].' kg other deduction';
        }
        $line = implode(', ', $parts).' → '.($data['quantity_kg'] ?? '').' kg net';

        return trim(($note ? $note."\n" : '').$line);
    }

    /** A one-line help shown above the fields. */
    public static function help(): Placeholder
    {
        return Placeholder::make('weights_help')->hiddenLabel()->columnSpanFull()
            ->content(__('Weighed in bags? Type the weight on the scale, the bags and the bardana: the net kg is worked out (weight − bags × bardana − other deduction).'));
    }
}
