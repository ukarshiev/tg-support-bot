<?php

namespace App\Modules\Translation\Support;

class PlaceholderProtector
{
    /**
     * Защитить переменные и ссылки от машинного перевода.
     *
     * Best practice для DeepL: отдаём переводчику XML-теги и потом возвращаем
     * исходные значения. Так переводчик видит структуру, но не должен переводить
     * содержимое тегов `<x>...</x>` при настройке `tag_handling=xml` + `ignore_tags=x`.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function protect(string $text): array
    {
        $map = [];
        $index = 0;

        $protected = preg_replace_callback(
            '/(\{\{\s*[A-Za-z0-9_]+\s*\}\}|\{[A-Za-z0-9_.:-]+\}|https?:\/\/[^\s<>"\']+|@[A-Za-z0-9_]{3,})/u',
            static function (array $matches) use (&$map, &$index): string {
                $id = 'tgph' . $index++;
                $value = $matches[0];
                $map[$id] = $value;

                return '<x id="' . $id . '">' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</x>';
            },
            $text
        );

        return [$protected ?? $text, $map];
    }

    /**
     * Вернуть защищённые фрагменты в переведённый текст.
     *
     * @param array<string, string> $map
     */
    public function restore(string $text, array $map): string
    {
        foreach ($map as $id => $value) {
            $text = preg_replace('/<x\s+id=["\']' . preg_quote($id, '/') . '["\']\s*>.*?<\/x>/isu', $value, $text) ?? $text;
            $text = str_replace(htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $value, $text);
        }

        // На случай, если провайдер сохранил тег, но изменил атрибуты/пробелы.
        $text = preg_replace('/<x\b[^>]*>(.*?)<\/x>/isu', '$1', $text) ?? $text;

        return $text;
    }
}
