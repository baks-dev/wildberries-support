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

namespace BaksDev\Wildberries\Support\Api\Question\ReplyToQuestion;

use BaksDev\Wildberries\Api\Wildberries;

final class PostWbReplyToQuestionRequest extends Wildberries
{
    /** Идентификатор чата */
    private string|false $chatId = false;

    /** Текст сообщения в формате plain text от 1 до 1000 символов */
    private string|false $message = false;

    /** Статус вопроса:
    *
    * none - вопрос отклонён продавцом (такой вопрос не отображается на портале покупателей)
    * wbRu - ответ предоставлен, вопрос отображается на сайте покупателей. */
    private string|false $state = false;

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

    public function state(string $state): self
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Метод позволяет ответить на вопрос
     * @see https://dev.wildberries.ru/ru/openapi/user-communication/#tag/Voprosy/paths/~1api~1v1~1questions/patch
     */
    public function sendAnswer(): bool
    {
        /**
         * Выполнять операции запроса ТОЛЬКО в PROD окружении
         */
        if(!$this->isExecuteEnvironment())
        {
            return false;
        }

        $json = [
            "id" => $this->chatId,
            "answer" => [
                "text" => $this->message,
            ],
            "state" => $this->state,
        ];

        $response = $this
            ->feedbacks()
            ->TokenHttpClient()
            ->request(
                method: 'PATCH',
                url: 'api/v1/questions',
                options: ["json" => $json]
            );

        $content = $response->getContent(false);

        if($response->getStatusCode() !== 200)
        {
            $this->logger->critical(
                sprintf('wildberries-support: Ошибка отправки ответа на вопрос'),
                [
                    $content,
                    self::class.':'.__LINE__,
                ]);

            return false;
        }

        return true;
    }
}