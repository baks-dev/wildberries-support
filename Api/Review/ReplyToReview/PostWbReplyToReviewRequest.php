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

namespace BaksDev\Wildberries\Support\Api\Review\ReplyToReview;

use BaksDev\Wildberries\Api\Wildberries;


final class PostWbReplyToReviewRequest extends Wildberries
{
    /** Идентификатор чата */
    private string|false $chatId = false;

    /** Текст сообщения в формате plain text от 1 до 1000 символов */
    private string|false $message = false;

    /** Идентификатор чата */
    public function chatId(string $chat): self
    {
        $this->chatId = $chat;

        return $this;
    }

    /** Текст сообщения в формате plain text от 1 до 1000 символов */
    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Метод позволяет ответить на отзыв покупателя.
     * @see https://dev.wildberries.ru/ru/openapi/user-communication/#tag/Otzyvy/paths/~1api~1v1~1feedbacks~1answer/post
     */
    public function sendMessage(): bool
    {
        /**
         * Выполнять операции запроса ТОЛЬКО в PROD окружении
         */
        if(false === $this->isExecuteEnvironment())
        {
            return false;
        }

        $json = [
            "id" => $this->chatId,
            "text" => $this->message,
        ];

        $response = $this
            ->feedbacks()
            ->TokenHttpClient()
            ->request(
                method: 'POST',
                url: 'api/v1/feedbacks/answer',
                options: ["json" => $json]
            );

        if($response->getStatusCode() !== 204)
        {
            $this->logger->critical(
                sprintf('wildberries-support: Ошибка отправки ответа на отзыв'),
                [
                    self::class.':'.__LINE__,
                ]);

            return false;
        }

        return true;
    }
}
