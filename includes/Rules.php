<?php
declare(strict_types=1);

namespace SpamEmailZA;

final class Rules {
    public const MAX_LENGTH = 24000;

    public static function defaults(): string {
        return <<<'RULES'
Оплата за рекламные лиды | привлечение клиентов / привлечению клиентов / привлечения клиентов / лидогенерация / лидогенерации | оплата за лиды / оплата за лидов / оплатой за лидов / оплатой только за лидов / оплата за заявки / с оплатой за заявки
Продвижение через Reels | reels аккаунтов / сетка reels / сетку reels | привлечение клиентов / привлечению клиентов / привлечение заявок / поток целевых обращений
Предложение рекламных услуг | предлагаю сотрудничество / предлагаем сотрудничество / коммерческое предложение / хотим предложить / предложение по сотрудничеству | seo продвижение / продвижение сайта / продвижение сайтов / настройку контекстной рекламы / настройка рекламы / настроить рекламу / настройку яндекс директ
Обещание потока заявок | гарантируем заявки / гарантированный поток заявок / гарантируем вам заявки / приводим целевых клиентов | продвижение бизнеса / привлечение клиентов / настройка рекламы / рекламную кампанию
Рекламное партнёрство через мессенджер | предлагаем партнёрство / предлагаю партнёрство / предлагаем сотрудничество | напишите в telegram / напишите в телеграм / свяжитесь со мной в telegram | продвижение / лидогенерация / рекламные услуги / привлечение клиентов
RULES;
    }

    /** @return list<string> Названия совпавших правил. */
    public static function match(string $text, string $source): array {
        $text = self::normalize($text);
        if ($text === '') return [];
        return array_keys(array_filter(self::rules($source), static function (array $groups) use ($text): bool {
            foreach ($groups as $group) {
                $pattern = '~(?:^| )(?:'.implode('|', array_map(static fn (string $phrase): string => preg_quote($phrase, '~'), $group)).')(?: |$)~iu';
                if (preg_match($pattern, $text) !== 1) return false;
            }
            return true;
        }));
    }

    /** Номер первой некорректной строки или null для корректного набора. */
    public static function invalidLine(string $source): ?int {
        self::rules($source, $invalid);
        return $invalid;
    }

    private static function rules(string $source, ?int &$invalid = null): array {
        $invalid = null;
        if (strlen($source) > self::MAX_LENGTH) {
            $invalid = 1;
            return [];
        }
        $rules = [];
        foreach (preg_split('/\R/u', $source) ?: [] as $index => $row) {
            $row = trim($row);
            if ($row === '' || $row[0] === '#') continue;
            $parts = array_map('trim', explode('|', $row));
            $label = array_shift($parts);
            $groups = [];
            $seen = [];
            foreach ($parts as $part) {
                $alternatives = array_map(self::normalize(...), preg_split('~\s+/\s+~u', $part) ?: []);
                if (count($alternatives) > 8 || !$alternatives || in_array('', $alternatives, true)) break;
                foreach ($alternatives as $term) {
                    if (strlen($term) > 240 || preg_grep('~^'.preg_quote($term, '~').'$~iuD', $seen)) break 2;
                    $seen[] = $term;
                }
                $groups[] = $alternatives;
            }
            if ($label === '' || strlen($label) > 160 || isset($rules[$label]) || count($rules) >= 80 || count($parts) < 2 || count($parts) > 5 || count($groups) !== count($parts)) {
                $invalid ??= $index + 1;
                continue;
            }
            $rules[$label] = $groups;
        }
        return $rules;
    }

    private static function normalize(string $text): string {
        $text = str_replace(['ё', 'Ё'], ['е', 'Е'], $text);
        $text = preg_replace('/\p{Cf}+/u', '', $text) ?? '';
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '');
    }
}
