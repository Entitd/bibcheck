<?php

namespace App\Services\Bibliography;

use App\DTO\ProviderResult;
use App\Services\ExternalApi\CrossrefProvider;
use App\Services\ExternalApi\DataCiteProvider;
use App\Services\ExternalApi\DblpProvider;
use App\Services\ExternalApi\OpenAlexProvider;
use App\Services\ExternalApi\OpenLibraryProvider;
use App\Services\ExternalApi\ProviderHttpClient;
use Illuminate\Support\Facades\Log;

/**
 * Проверяет одну библиографическую запись во внешних базах.
 * Сам не читает HTTP-запрос и не сохраняет результат в БД: это делает вызывающий код.
 * Путь данных: fields → нормализация → выбор баз → поиск → сравнение → итоговый массив.
 */
class SourceVerificationService
{
    // Laravel передаёт сюда сервисы обработки.
    // Поля конкретной записи приходят отдельно в verify().
    public function __construct(private SourceNormalizer $normalizer, private SourceMatcher $matcher, private ProviderRouter $router) {}

    /**
     * @param array $fields Поля одной разобранной записи: title, author, year, doi и т. д.
     * @param string $type Тип BibTeX/BibLaTeX, например article или book.
     * @param bool $defer true: передать DeferredCheck очереди для переноса задания;
     *                    false: вернуть неполный результат без ожидания и повтора.
     * @param int|null $requestBudget Оставшийся бюджет сетевых вызовов; null — лимит из config.
     * @param callable|null $reserveRequest Функция резервирования вызова в БД перед HTTP-запросом.
     *                                      Возвращает false, если тратить запрос уже нельзя.
     */
    public function verify(array $fields, string $type = 'article', bool $defer = false, ?int $requestBudget = null, ?callable $reserveRequest = null): array
    {


    // Log::channel('external_db')->info("my test log");
    // Log::channel('external_db')->info("fields = " . print_r($fields, true));
    // Log::channel('external_db')->info("type = " . print_r($type, true));
    // Log::channel('external_db')->info("defer = " . print_r($defer, true));
    // Log::channel('external_db')->info("requestBudget = " . print_r($requestBudget, true));
    // Log::channel('external_db')->info("reserveRequest = " . print_r($reserveRequest, true));


        // Исходные поля превращаются в SourceQuery: очищенные название, авторы,
        // DOI/ISBN и год, а также ошибки формата и предупреждения.
        $query = $this->normalizer->query($fields, $type);
        // Каркас ответа. found — установлена ли личность публикации;
        // complete — удалось ли выполнить проверку без сбоев/неполноты данных.
        // similarity — сходство названий, field_checks — сравнение отдельных полей,
        // provider_checks — диагностика баз, candidates — альтернативные публикации.
        // complete=true здесь только начальное значение, ниже оно может измениться.
        $base = ['schema_version' => 2, 'algorithm_version' => config('bibliography.algorithm_version'),
            'found' => false, 'complete' => true, 'matched_by' => null, 'provider' => null,
            'similarity' => null, 'external_title' => null, 'external_data' => null, 'field_checks' => [],
            'candidates' => [], 'provider_checks' => [], 'http_requests' => 0, 'warnings' => $query->warnings,
            'retraction' => ['status' => 'unknown', 'notice_url' => null], 'checked_at' => now()->toIso8601String()];
        // Некорректный идентификатор и другие ошибки входа: внешние API не вызываем.
        if ($query->error) {
            Log::channel('external_db')->info("какой-то косяк присутсвует = " . print_r($query->error, true));
            return $this->finish($base, 'invalid_input', $query->error);
        }
        // Порядок баз зависит от типа и идентификаторов. Router также исключает
        // отключённые базы и OpenAlex без серверного ключа.
        $route = $this->router->route($query);
        // Нет доступной проверки или данных для поиска — это не «источник не найден».
        if (! config('bibliography.enabled') || ! $route || (! $query->title && ! $query->doi && ! $query->isbn)) {
            return $this->finish($base, 'not_checkable');
        }
        // Один HTTP-клиент на всю запись: общий бюджет для всех баз.
        // Он отвечает за кеш, сетевые вызовы, таймауты и ограничения частоты.
        $http = app()->makeWith(ProviderHttpClient::class, ['budget' => $requestBudget, 'reserveRequest' => $reserveRequest]);
        // Соответствие имён из Router конкретным классам провайдеров.
        $classes = ['crossref' => CrossrefProvider::class, 'datacite' => DataCiteProvider::class,
            'openlibrary' => OpenLibraryProvider::class, 'dblp' => DblpProvider::class, 'openalex' => OpenAlexProvider::class];
        // Здесь накапливаем подходящие результаты из всех уже опрошенных баз.
        $candidates = [];
        // Самый похожий ответ сохраняем отдельно, даже если он ниже порога 60.
        $nearest = null;
        // DOI имеет приоритет над ISBN. Без обоих ищем по метаданным.
        $identifier = $query->doi ? 'doi' : ($query->isbn ? 'isbn' : null);

        // Log::channel('external_db')->info("route = " . print_r($route, true));

        // Базы вызываются последовательно; сильное совпадение может завершить цепочку.
        foreach ($route as $name) {
            
            // Log::channel('external_db')->info("name = " . print_r($name, true));

            // Создаём провайдер через контейнер Laravel, передавая общий HTTP-клиент.
            $provider = app()->makeWith($classes[$name], ['http' => $http]);

            // Log::channel('external_db')->info("provider = " . print_r($provider, true));


            try {
                // $query->$identifier — это $query->doi либо $query->isbn.
                // Провайдер возвращает ProviderResult: статус, кандидатов и диагностику.
                $result = $identifier ? $provider->findByIdentifier($identifier, $query->$identifier) : $provider->search($query);
            } catch (DeferredCheck $e) {
                // Например, слот базы занят или нужно выдержать паузу между запросами.
                // В очереди исключение уйдёт в Job, который перенесёт выполнение.
                $e->requests = $http->requests;
                if ($defer) {
                    throw $e;
                }
                // Без очереди фиксируем ограничение и пробуем следующую базу.
                $base['provider_checks'][] = ['provider' => $name, 'status' => 'rate_limited', 'cached' => false, 'retry_after' => $e->delay];
                $base['complete'] = false;

                continue;
            } catch (\TypeError|\UnexpectedValueException|\ErrorException $e) {
                // Ошибка типов/структуры ответа не равна отсутствию публикации.
                $result = new ProviderResult($name, 'malformed_response');
            }
            // Считаются реальные HTTP-вызовы; чтение из кеша бюджет не расходует.
            $base['http_requests'] = $http->requests;
            $base['provider_checks'][] = $result->diagnostic();
            // Запасной путь для книг: если ISBN не найден, ищем по названию.
            // Результат второго поиска заменяет $result, обе диагностики сохраняются.
            if ($identifier === 'isbn' && $name === 'openlibrary' && $result->status === 'not_found' && $query->title) {
                try {
                    $result = $provider->search($query);
                    $base['provider_checks'][] = $result->diagnostic();
                } catch (DeferredCheck $e) {
                    $e->requests = $http->requests;
                    if ($defer) {
                        throw $e;
                    }
                    $base['complete'] = false;

                    continue;
                }
            }
            $base['http_requests'] = $http->requests;
            // found/not_found — нормальные ответы базы; остальные статусы означают
            // сбой, ограничение или пропущенный вызов, поэтому проверка неполная.
            if (! in_array($result->status, ['found', 'not_found'])) {
                $base['complete'] = false;
            }
            // Кандидаты уже приведены провайдером к общему формату полей.
            foreach ($result->candidates as $candidate) {
                // Например, не удалось получить все карточки авторов или изданий.
                if (($candidate['metadata_complete'] ?? true) === false) {
                    $base['complete'] = false;
                }
                // Matcher проверяет поля и возвращает status, found, similarity,
                // field_checks и внутренний балл score для сортировки.
                $match = $this->matcher->compare($query, $candidate, $identifier);
                // Запасной кандидат выбирается по сходству названия среди всех баз.
                // Без названия сравнение невозможно — такую карточку не предлагаем.
                if ($match['similarity'] !== null && ($nearest === null || $match['similarity'] > $nearest['match']['similarity'])) {
                    $nearest = ['provider' => $name, 'data' => $candidate, 'match' => $match];
                }
                // Совпавший DOI/ISBN позволяет сразу вернуть результат.
                // Статус может быть mismatch, если остальные поля противоречат карточке.
                // Эта ветка не проходит финальное понижение статуса при complete=false ниже.
                if ($identifier && ($match['field_checks'][$identifier]['status'] ?? null) === 'equal') {
                    // В array_replace значения правых массивов заменяют значения левых.
                    // retraction — сведения об отзыве; оператор + добавляет отсутствующий checked_at.
                    $base = array_replace($base, $match, ['provider' => $name, 'matched_by' => $identifier,
                        'external_title' => $candidate['title'] ?? null, 'external_data' => $candidate,
                        'retraction' => ($candidate['retraction'] ?? $base['retraction']) + ['checked_at' => now()->toIso8601String()]]);
                    // Внутренний балл ранжирования не включаем в итоговый ответ.
                    unset($base['score']);

                    return $this->finish($base, $match['status']);
                }
                // 60 — порог сохранения кандидата, а не порог подтверждения записи.
                // Для verified Matcher использует более строгие условия.
                if (($match['similarity'] ?? 0) >= 60) {
                    $candidates[] = ['provider' => $name, 'data' => $candidate, 'match' => $match];
                }
            }
            // Без идентификатора можно остановиться, если лучший кандидат verified
            // и достаточно опережает второго. score — балл, а не вероятность.
            if (! $identifier && $result->candidates) {
                // Сортируем копию накопленного списка по убыванию score.
                $ranked = $candidates;
                usort($ranked, fn ($a, $b) => $b['match']['score'] <=> $a['match']['score']);
                if (($ranked[0]['match']['status'] ?? null) === 'verified' && (count($ranked) === 1 || $ranked[0]['match']['score'] - $ranked[1]['match']['score'] >= config('bibliography.candidate_gap'))) {
                    break;
                }
            }
        }
        // Нет подходящих кандидатов: различаем обычный пустой поиск и сбой проверки.
        // not_found не доказывает, что публикация вымышлена.
        if (! $candidates) {
            if ($nearest !== null) {
                // Это подсказка для ручной проверки, а не подтверждённая публикация.
                // Статус not_found/incomplete и found=false сохраняются.
                // Сведения об отзыве чужой карточки к исходной записи не переносим.
                $base = array_replace($base, [
                    'nearest_match' => true, 'provider' => $nearest['provider'],
                    'matched_by' => 'nearest_metadata', 'similarity' => $nearest['match']['similarity'],
                    'external_title' => $nearest['data']['title'] ?? null, 'external_data' => $nearest['data'],
                    'field_checks' => $nearest['match']['field_checks'],
                ]);
            }

            return $this->finish($base, $base['complete'] ? 'not_found' : 'incomplete');
        }
        // После обхода баз выбираем лучший результат из общего списка.
        usort($candidates, fn ($a, $b) => $b['match']['score'] <=> $a['match']['score']);
        $best = $candidates[0];
        $status = $best['match']['status'];
        // Близкие баллы двух кандидатов не позволяют уверенно выбрать публикацию.
        if (count($candidates) > 1 && $best['match']['score'] - $candidates[1]['match']['score'] < config('bibliography.candidate_gap')) {
            $status = 'ambiguous';
        }
        // Собираем карточку лучшего результата и до пяти альтернатив для интерфейса.
        $base = array_replace($base, $best['match'], ['provider' => $best['provider'], 'matched_by' => 'metadata',
            'external_title' => $best['data']['title'] ?? null, 'external_data' => $best['data'],
            'candidates' => array_map(fn ($c) => ['provider' => $c['provider'], 'data' => $c['data'], 'similarity' => $c['match']['similarity']], array_slice($candidates, 0, 5)),
            'retraction' => ($best['data']['retraction'] ?? $base['retraction']) + ['checked_at' => now()->toIso8601String()]]);
        // При неоднозначности не считаем личность публикации установленной.
        if ($status === 'ambiguous') {
            $base['found'] = false;
        }
        // На пути поиска по метаданным неполная проверка не даёт строгого verified.
        if (! $base['complete'] && $status === 'verified') {
            $status = 'partial_match';
        }
        unset($base['score']);

        return $this->finish($base, $status);
    }

    // Добавляет итоговый статус и понятное сообщение. В БД этот метод ничего не пишет:
    // сохранение и отображение массива выполняются снаружи этого сервиса.
    private function finish(array $result, string $status, ?string $message = null): array
    {
        $messages = ['verified' => 'Публикация найдена, проверяемые поля согласуются.',
            'mismatch' => 'Публикация найдена, но есть расхождения в полях.',
            'partial_match' => 'Найдено частичное подтверждение; часть данных не подтверждена.',
            'ambiguous' => 'Найдено несколько близких кандидатов.',
            'not_found' => 'Поиск в выбранных базах не дал результата. Это не доказывает, что источник вымышлен.',
            'not_checkable' => 'Нет подходящей проверки для этого типа или набора данных.',
            'incomplete' => 'Проверка не завершена из-за ограничения или сбоя внешней базы.',
            'invalid_input' => 'Некорректные исходные данные.'];

        // Переданное сообщение (например, ошибка нормализации) имеет приоритет над шаблоном.
        return array_replace($result, ['status' => $status, 'message' => $message ?? $messages[$status]]);
    }
}
