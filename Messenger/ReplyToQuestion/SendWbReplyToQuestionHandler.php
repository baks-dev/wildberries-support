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

namespace BaksDev\Wildberries\Support\Messenger\ReplyToQuestion;

use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Support\Messenger\SupportMessage;
use BaksDev\Support\Repository\SupportCurrentEvent\CurrentSupportEventRepository;
use BaksDev\Support\Type\Status\SupportStatus\Collection\SupportStatusClose;
use BaksDev\Support\UseCase\Admin\New\Message\SupportMessageDTO;
use BaksDev\Support\UseCase\Admin\New\SupportDTO;
use BaksDev\Wildberries\Support\Api\Question\ReplyToQuestion\PostWbReplyToQuestionRequest;
use BaksDev\Wildberries\Support\Type\WbQuestionProfileType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendWbReplyToQuestionHandler
{
    public function __construct(
        #[Target('wildberriesSupportLogger')] private LoggerInterface $logger,
        private MessageDispatchInterface $messageDispatch,
        private CurrentSupportEventRepository $currentSupportEvent,
        private PostWbReplyToQuestionRequest $postWbReplyToQuestionRequest,
    ) {}

    /**
     * При ответе на пользовательские сообщения:
     * - получаем текущее событие чата;
     * - проверяем статус чата - наши ответы закрывают чат - реагируем на статус SupportStatusClose;
     * - отправляем последнее добавленное сообщение - наш ответ;
     * - в случае ошибки WB API повторяем текущий процесс через интервал времени.
     */

    public function __invoke(SupportMessage $message): void
    {
        $supportDTO = new SupportDTO();

        $supportEvent = $this->currentSupportEvent
            ->forSupport($message->getId())
            ->find();

        if(false === $supportEvent)
        {
            $this->logger->critical(
                'Ошибка получения события по идентификатору :'.$message->getId(),
                [self::class.':'.__LINE__],
            );

            return;
        }

        $supportEvent->getDto($supportDTO);

        $SupportInvariableDTO = $supportDTO->getInvariable();

        if(is_null($SupportInvariableDTO))
        {
            return;
        }

        /**
         * Пропускаем если тикет не является Support Question «Вопрос»
         */
        $typeProfile = $SupportInvariableDTO->getType();

        if(false === $typeProfile->equals(WbQuestionProfileType::TYPE))
        {
            return;
        }

        /**
         * Ответ только на закрытый тикет
         */
        if(false === ($supportDTO->getStatus()->getSupportStatus() instanceof SupportStatusClose))
        {
            return;
        }

        /**
         * Первое сообщение, содержащее идентификатор вопроса
         * @var SupportMessageDTO $firstMessage
         */

        $firstMessage = $supportDTO->getMessages()->first();

        // Первый элемент имеет id вопроса для ответа
        if(is_null($firstMessage->getExternal()))
        {
            return;
        }

        /**
         * Последнее сообщение для ответа
         * @var SupportMessageDTO $lastMessage
         */

        $lastMessage = $supportDTO->getMessages()->last();

        $ticket = $SupportInvariableDTO->getTicket();

        // проверяем наличие внешнего ID - если он есть, значит вопрос закрыли без ответа

        $messageText = null !== $lastMessage->getExternal() ? "" : $lastMessage->getMessage();
        $state = null !== $lastMessage->getExternal() ? "none" : "wbRu";

        /**
         * Формируем ответ на вопрос
         */

        $result = $this->postWbReplyToQuestionRequest
            ->chatId($ticket)
            ->message($messageText)
            ->state($state)
            ->sendAnswer();

        if(false === $result)
        {
            $this->logger->warning(
                'Повтор отправки ответа на вопрос через 1 минут',
                [self::class.':'.__LINE__],
            );

            $this->messageDispatch
                ->dispatch(
                    message: $message,
                    stamps: [new MessageDelay('1 minute')],
                    transport: 'wildberries-support-low',
                );
        }
    }
}