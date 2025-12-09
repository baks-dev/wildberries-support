<?php
/*
 * Copyright 2025.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Wildberries\Support\Api\Question\QuestionsList;

use BaksDev\Wildberries\Api\Wildberries;
use BaksDev\Wildberries\Support\Schedule\WbNewQuestion\FindProfileForCreateWbQuestionSchedule;
use DateInterval;
use Generator;
use Symfony\Contracts\Cache\ItemInterface;

final class GetWbQuestionsListRequest extends Wildberries
{
    private int|false $from = false;

    const int LIMIT = 10000;

    public function from(int $time): self
    {
        $this->from = $time;

        return $this;
    }

    /**
     * Метод предоставляет список вопросов по заданным фильтрам, получить данные отвеченных и неотвеченных вопросов,
     * сортировать вопросы по дате и настроить пагинацию и количество вопросов в ответе
     *
     * @see https://dev.wildberries.ru/ru/openapi/user-communication/#tag/Voprosy/paths/~1api~1v1~1questions/get
     * @return Generator<WbQuestionMessageDTO>|false
     */
    public function findAll(): Generator|false
    {
        $skip = 0;
        $take = self::LIMIT;

        while(true)
        {
            $cache = $this->getCacheInit('wildberries-support');
            $key = md5(self::class.$this->getProfile().$this->from.$skip.$take);

            $query = [
                'isAnswered' => false,
                'take' => $take,
                'skip' => $skip,
            ];

            if($this->from !== false)
            {
                $query['dateFrom'] = $this->from;
            }

            $content = $cache->get($key, function(ItemInterface $item) use ($query) {
                $item->expiresAfter(DateInterval::createFromDateString('1 seconds'));

                $response = $this
                    ->feedbacks()
                    ->TokenHttpClient()
                    ->request(
                        method: 'GET',
                        url: 'api/v1/questions',
                        options: [
                            'query' => $query,
                        ]
                    );

                $content = $response->toArray(false);

                if($response->getStatusCode() !== 200)
                {
                    $this->logger->critical(
                        sprintf('wildberries-support: Ошибка %s получения списка вопросов', $response->getStatusCode()),
                        [
                            self::class.':'.__LINE__,
                            $content
                        ]);

                    return false;
                }

                $item->expiresAfter(
                    DateInterval::createFromDateString(
                        FindProfileForCreateWbQuestionSchedule::INTERVAL
                    )
                );

                return $content;
            });

            $questions = $content['data']['questions'];

            foreach($questions as $question)
            {
                yield new WbQuestionMessageDTO($question);
            }

            if(empty($questions) || count($questions) < self::LIMIT)
            {
                break;
            }

            $skip += self::LIMIT;
        }


    }
}
