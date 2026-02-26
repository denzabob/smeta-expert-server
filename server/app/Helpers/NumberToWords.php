<?php

namespace App\Helpers;

/**
 * Класс для конвертации чисел в текстовое представление на русском языке
 * Корректно обрабатывает женский род для тысяч и склонение рублей/копеек
 */
class NumberToWords
{
    // Единицы: мужской род (для рублей, миллионов, миллиардов)
    private static array $ones_m = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    
    // Единицы: женский род (для тысяч)
    private static array $ones_f = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    
    private static array $teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
    
    private static array $tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
    
    private static array $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
    
    // [единственное, 2-4, множественное, род: m - мужской, f - женский]
    private static array $scales = [
        ['', '', '', 'm'], // единицы (рубли обрабатываются отдельно)
        ['тысяча', 'тысячи', 'тысяч', 'f'],
        ['миллион', 'миллиона', 'миллионов', 'm'],
        ['миллиард', 'миллиарда', 'миллиардов', 'm'],
        ['триллион', 'триллиона', 'триллионов', 'm'],
    ];

    /**
     * Конвертирует число в текстовое представление с рублями и копейками
     */
    public static function convert(float $number): string
    {
        $number = round($number, 2);
        $rubles = (int) $number;
        $kopecks = (int) round(($number - $rubles) * 100);

        if ($rubles == 0) {
            $result = 'ноль';
        } else {
            $words = [];
            $temp_rubles = $rubles;

            for ($scale = 0; $scale < count(self::$scales) && $temp_rubles > 0; $scale++) {
                $group = $temp_rubles % 1000;
                $temp_rubles = intdiv($temp_rubles, 1000);

                if ($group == 0) {
                    continue;
                }

                $group_words = '';
                $hundreds_digit = intdiv($group, 100);
                $remainder = $group % 100;

                if ($hundreds_digit > 0) {
                    $group_words .= self::$hundreds[$hundreds_digit] . ' ';
                }

                if ($remainder >= 10 && $remainder < 20) {
                    $group_words .= self::$teens[$remainder - 10] . ' ';
                } else {
                    $tens_digit = intdiv($remainder, 10);
                    $ones_digit = $remainder % 10;

                    if ($tens_digit > 0) {
                        $group_words .= self::$tens[$tens_digit] . ' ';
                    }
                    if ($ones_digit > 0) {
                        // Выбираем род: женский для тысяч, мужской для остальных
                        $ones = (self::$scales[$scale][3] === 'f') ? self::$ones_f : self::$ones_m;
                        $group_words .= $ones[$ones_digit] . ' ';
                    }
                }

                // Добавляем название разряда (тысяча, миллион и т.д.)
                if ($scale > 0) {
                    $group_words .= self::getForm($group, self::$scales[$scale]) . ' ';
                }

                array_unshift($words, trim($group_words));
            }

            $result = trim(implode(' ', $words));
        }

        // Склонение слова "рубль"
        $ruble_forms = ['рубль', 'рубля', 'рублей'];
        $result .= ' ' . self::getForm($rubles, $ruble_forms);

        // Добавляем копейки
        $kopeck_forms = ['копейка', 'копейки', 'копеек'];
        $result .= ' ' . sprintf('%02d', $kopecks) . ' ' . self::getForm($kopecks, $kopeck_forms);

        return $result;
    }

    /**
     * Выбор формы слова по числу (склонение)
     */
    private static function getForm(int $n, array $forms): string
    {
        $n = abs($n) % 100;
        if ($n >= 11 && $n <= 19) {
            return $forms[2];
        }
        $n = $n % 10;
        if ($n == 1) {
            return $forms[0];
        }
        if ($n >= 2 && $n <= 4) {
            return $forms[1];
        }
        return $forms[2];
    }
}
