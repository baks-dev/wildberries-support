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

namespace BaksDev\Wildberries\Support\Messenger\ReplyToChat;

use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Support\Entity\Event\SupportEvent;
use BaksDev\Support\Messenger\SupportMessage;
use BaksDev\Support\Repository\SupportCurrentEvent\CurrentSupportEventInterface;
use BaksDev\Support\Type\Status\SupportStatus\Collection\SupportStatusClose;
use BaksDev\Support\UseCase\Admin\New\Message\SupportMessageDTO;
use BaksDev\Support\UseCase\Admin\New\SupportDTO;
use BaksDev\Wildberries\Support\Api\Chat\ReplyToChat\PostWbReplyToChatRequest;
use BaksDev\Wildberries\Support\Type\WbChatProfileType;
use BaksDev\Wildberries\Type\id\WbTokenUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendWbMessageChatDispatcher
{
    public function __construct(
        #[Target('wildberriesSupportLogger')] private LoggerInterface $logger,
        private MessageDispatchInterface $messageDispatch,
        private CurrentSupportEventInterface $CurrentSupportEventRepository,
        private PostWbReplyToChatRequest $sendMessageRequest,
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
        $SupportEvent = $this->CurrentSupportEventRepository
            ->forSupport($message->getId())
            ->find();

        if(false === ($SupportEvent instanceof SupportEvent))
        {
            $this->logger->critical(
                'Ошибка получения события по идентификатору :'.$message->getId(),
                [self::class.':'.__LINE__],
            );

            return;
        }

        /** @var SupportDTO $SupportDTO */
        $SupportDTO = $SupportEvent->getDto(SupportDTO::class);
        $SupportInvariableDTO = $SupportDTO->getInvariable();

        if(is_null($SupportInvariableDTO))
        {
            return;
        }

        /**
         * Ответ только на закрытый тикет
         */
        if(false === $SupportDTO->getStatus()->equals(SupportStatusClose::class))
        {
            return;
        }

        /**
         * Пропускаем если тикет не является Wildberries Support Chat «Чат с покупателем»
         */
        $typeProfile = $SupportInvariableDTO->getType();

        if(false === $typeProfile->equals(WbChatProfileType::TYPE))
        {
            return;
        }

        /** @var SupportMessageDTO $firstMessage */
        $firstMessage = $SupportDTO->getMessages()->first();

        /** @var SupportMessageDTO $lastMessage */
        $lastMessage = $SupportDTO->getMessages()->last();

        // проверяем наличие внешнего ID - для наших ответов его быть не должно
        if($lastMessage->getExternal() !== null)
        {
            return;
        }

        $replySign = $firstMessage->getExternal();
        $lastMessageText = $lastMessage->getMessage();

        $result = $this->sendMessageRequest
            ->forTokenIdentifier(new WbTokenUid($SupportDTO->getToken()->getValue()))
            ->replySign($replySign)
            ->message($lastMessageText)
            ->sendMessage();

        if(false === $result)
        {
            $this->logger->warning(
                'Повтор выполнения сообщения через 1 минут',
                [self::class.':'.__LINE__],
            );

            $this->messageDispatch
                ->dispatch(
                    message: $message,
                    // задержка 1 минуту для отправки сообщение в существующий чат по его идентификатору
                    stamps: [new MessageDelay('1 minutes')],
                    transport: 'wildberries-support',
                );
        }
    }
}