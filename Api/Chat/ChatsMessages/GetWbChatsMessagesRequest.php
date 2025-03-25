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

namespace BaksDev\Wildberries\Support\Api\Chat\ChatsMessages;

use BaksDev\Wildberries\Api\Wildberries;
use DateInterval;
use Generator;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Метод позволяет получить список событий (сообщений).
 * https://dev.wildberries.ru/ru/openapi/user-communication#tag/Chat-s-pokupatelyami/paths/~1api~1v1~1seller~1events/get
 */
final class GetWbChatsMessagesRequest extends Wildberries
{
    /** Необязательное свойство для пагинации. С какого момента получить следующий пакет данных. Формат Unix timestamp с миллисекундами */
    private int|false $next = false;

    public function next(int $next): self
    {
        $this->next = $next;

        return $this;
    }

    /** Вернет сообщения, начиная со самых старых */
    public function findAll(): Generator|false
    {
        $this->buyerChat();

        while(true)
        {
            $cache = $this->getCacheInit('wildberries-support');
            $key = md5(self::class.$this->getProfile().$this->next);

            $content = $cache->get($key, function(ItemInterface $item) {
                $item->expiresAfter(DateInterval::createFromDateString('1 seconds'));

                $response = $this
                    ->TokenHttpClient()
                    ->request(
                        method: 'GET',
                        url: 'api/v1/seller/events',
                        options: $this->next === false ? [] : [
                            "query" => [
                                "next" => $this->next,
                            ]
                        ],
                    );
                $content = $response->toArray(false);

                if($response->getStatusCode() !== 200)
                {
                    $this->logger->critical(
                        sprintf('wildberries-support: Ошибка получения списка сообщений'),
                        [
                            self::class.':'.__LINE__,
                            $content
                        ]);
                    return false;
                }

                $item->expiresAfter(DateInterval::createFromDateString('1 hours'));

                return $content;
            });

            if(count($content['result']['events']) === 0)
            {
                break;
            }

            /** @var array $chat */
            foreach($content['result']['events'] as $chat)
            {
                /** Пропустить, если сообщение пустое или если тип события - возврат */
                if(!isset($chat['message']) || $chat['eventType'] === 'refund')
                {
                    continue;
                }

                yield new WbChatMessageDTO($chat);
            }

            $this->next = $content['result']['next'];
        }
    }


}
