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

namespace BaksDev\Wildberries\Support\Messenger\ReplyToReview;

use DateTimeImmutable;
use BaksDev\Support\Entity\Support;
use BaksDev\Support\Repository\SupportCurrentEvent\CurrentSupportEventRepository;
use BaksDev\Support\Type\Status\SupportStatus;
use BaksDev\Support\Type\Status\SupportStatus\Collection\SupportStatusClose;
use BaksDev\Support\Type\Status\SupportStatus\Collection\SupportStatusOpen;
use BaksDev\Support\UseCase\Admin\New\Message\SupportMessageDTO;
use BaksDev\Support\UseCase\Admin\New\SupportDTO;
use BaksDev\Support\UseCase\Admin\New\SupportHandler;
use BaksDev\Wildberries\Support\Type\WbReviewProfileType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]

final readonly class AutoReplyWbReviewDispatcher
{
    public function __construct(
        #[Target('wildberriesSupportLogger')] private readonly LoggerInterface $logger,
        private readonly SupportHandler $supportHandler,
        private readonly CurrentSupportEventRepository $currentSupportEvent,
    ) {}

    public function __invoke(AutoReplyWbReviewMessage $message): void
    {
        $supportDTO = new SupportDTO();

        $supportEvent = $this->currentSupportEvent
            ->forSupport($message->getId())
            ->find();

        if(false === $supportEvent)
        {
            $this->logger->critical(
                'wildberries-support: Ошибка получения события по идентификатору :'.$message->getId(),
                [self::class.':'.__LINE__],
            );

            return;
        }

        // гидрируем DTO активным событием
        $supportEvent->getDto($supportDTO);

        // обрабатываем только на открытый тикет
        if(false === ($supportDTO->getStatus()->getSupportStatus() instanceof SupportStatusOpen))
        {
            return;
        }

        $supportInvariableDTO = $supportDTO->getInvariable();

        if(is_null($supportInvariableDTO))
        {
            return;
        }

        // проверяем тип профиля у чата
        $supportProfileType = $supportInvariableDTO->getType();

        if(false === $supportProfileType->equals(WbReviewProfileType::TYPE))
        {
            return;
        }

        // формируем сообщение в зависимости от условий отзыва
        $reviewRating = $message->getRating();

        $hello = [
            'Благодарим Вас за то, что выбрали наш магазин для покупки!',
            'Спасибо, что выбрали наш магазин для покупки!',
            'Спасибо, что выбрали именно нас!',
            'Благодарим вас за то, что остановили свой выбор на нашем магазине!',
            'Спасибо, что сделали выбор в пользу нашего магазина!',
        ];

        $key = array_rand($hello, 1);
        $hello = $hello[$key];


        /**
         * Текст сообщения в зависимости от рейтинга
         */

        if($reviewRating === 5)
        {
            $answerMessage = null;
            $answerMessage[] = 'Мы ценим Ваше доверие и всегда стремимся предоставить лучший сервис и продукт высокого качества.';
            $answerMessage[] = 'Мы стремимся предоставить только лучшее, и ваша покупка подтверждает это.';
            $answerMessage[] = 'Мы ценим вашу поддержку и уверены, что вы будете довольны своим приобретением.';
            $answerMessage[] = 'Мы рады, что вы стали нашим клиентом, и надеемся, что ваш опыт с нашей продукцией будет исключительно положительным.';
            $answerMessage[] = 'Мы уверены, что наша продукция оправдает ваши ожидания.';
            $answerMessage[] = 'Мы уверены, что вы будете довольны качеством нашей продукции.';

            $key = array_rand($answerMessage, 1);
            $answerMessage = $answerMessage[$key];

        }

        if($reviewRating === 4 || $reviewRating === 3)
        {
            $answerMessage = null;
            $answerMessage[] = 'Спасибо за ваш отзыв и высокую оценку! Мы рады узнать, что в целом вы остались довольны покупкой в нашем магазине.';
            $answerMessage[] = 'Благодарим за обратную связь! Рады, что в целом вы остались довольны.';
            $answerMessage[] = 'Благодарим за обратную связь! Мы работаем над улучшениями и надеемся, что в следующий раз вы поставите 5 звезд.';
            $answerMessage[] = 'Рады, что в целом вам понравилось и вы остались довольны. Ваши замечания помогут нам стать лучше.';
            $answerMessage[] = 'Спасибо за ваш отзыв! Мы рады, что в целом вы остались довольны, и всегда готовы к улучшениям.';

            $key = array_rand($answerMessage, 1);
            $answerMessage = $answerMessage[$key];
        }


        if($reviewRating < 3)
        {
            $answerMessage = null;
            $answerMessage[] = 'Извините за возникшие неудобства. Нам жаль, что вы остались недовольны.';
            $answerMessage[] = 'Приносим искренние извинения за возможные неудобства, которые могло вызвать у вас.';
            $answerMessage[] = 'Нам важно ваше мнение! Мы надеемся, что вы дадите нам еще один шанс. Приносим искренние извинения за возможные неудобства, которые могло вызвать у вас.';
            $answerMessage[] = 'Мы надеемся, что вы позволите восстановить ваше доверие к нам. Приносим искренние извинения за возможные неудобства, которые могло вызвать у вас.';
            $answerMessage[] = 'Нам жаль, что вы остались недовольны. Извините за возникшие неудобства. Надеемся, вы вернетесь, чтобы увидеть наши изменения.';
            $answerMessage[] = 'Мы ценим ваш отзыв и будем работать над улучшениями. Извините за возникшие неудобства.';
            $answerMessage[] = 'Приносим извинения за возможные неудобства. Мы надеемся на возможность сделать ваш следующий визит лучше.';
            $answerMessage[] = 'Надеемся, вы вернетесь, чтобы увидеть наши улучшения. Нам жаль, что вы остались недовольны.';

            $key = array_rand($answerMessage, 1);
            $answerMessage = $answerMessage[$key];
        }

        /**
         * Прощальный текст
         */

        $goodbye = [
            'Ждем вас в нашем магазине вновь!',
            'Будем рады видеть вас снова!',
            'Всего хорошего! Ждем вас в следующий раз!',
            'Нам будет приятно вас снова увидеть!',
            'Всегда рады вам! Увидимся в следующий раз!',
            'Спасибо за ваше время! Ждем вас снова!',
            'Мы ценим ваше мнение и ждем вас снова!',
            'Мы будем рады вашему возвращению!',
        ];

        $key = array_rand($goodbye, 1);
        $goodbye = $goodbye[$key];


        /** Отправляем сообщение клиенту */
        /** @var string $answerMessage */
        $supportMessageDTO = new SupportMessageDTO()
            ->setName('admin (WB Seller)')
            ->setMessage($hello.PHP_EOL.$answerMessage.PHP_EOL.$goodbye)
            ->setDate(new DateTimeImmutable('now'))
            ->setOutMessage();

        $supportDTO
            ->setStatus(new SupportStatus(SupportStatusClose::PARAM)) // закрываем чат
            ->addMessage($supportMessageDTO) // добавляем сформированное сообщение
        ;

        // сохраняем ответ
        $Support = $this->supportHandler->handle($supportDTO);

        if(false === ($Support instanceof Support))
        {
            $this->logger->critical(
                'wildberries-support: Ошибка при отправке автоматического ответа на отзыв',
                [$Support, self::class.':'.__LINE__]
            );
        }
    }
}