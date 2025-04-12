<?php

/**
 * Это фрагмент класса SyncCommand для запуска синхронизации платежей между Альфа-Банком и МойСклад.
 * Фрагмент из реального проекта, с которым Вам придется работать.
 *
 * Класс ah - Обертка над массивом для удобной работы с ним.
 * PaymentsIn - Входящий платеж МойСклад (пример данных во вложении)
 * InvoiceOut - Счет покупателю в МойСклад (пример данных во вложении)
 * MoyskladApp - Клиент для доступа к API МойСклад
 */

/**
 * В компанию обратился клиент со следующей проблемой:
 *
 * 200 входящих платежей у клиента привязываются к одному и тому же счету, хотя у каждого входящего платежа
 * должен быть свой индивидуальный счет к которому он должен быть привязан.
 *
 * В аудите платежей видно, что все они были созданы в одно и то же время нашей интеграцией.
 * Платежи действительно имеют разное назначение и содержат корректный номер счета.
 *
 * Пример назначения платежа из кейса:
 * "Оплата по сч/ф 1020 от 19.02.2025 по договору № Б\Н от 16.12.2024 за
 * Закупка поломоечных машин ТР ЮГ в т.ч. НДС 40.487,50"
 */

/**
 * Задача:
 *
 * 1. Выяснить, по какой причине произошла некорректная привязка платежей к счету.
 * 2. Внести изменения в код, чтобы кейс больше не повторился.
 * 3. Сделать рефакторинг метода, учучшив его читаемость и понятность.
 */

class SyncCommand {

    /**
     * Метод отвечает за связываение платежа и счета покупателю. Это необходимо для того,
     * чтобы менеджеры понимали, что данный счет уже оплачен.
     *
     * @param ah $paymentsIn
     * @param MoyskladApp $msApp
     * @return void|null
     */
    protected function attachToInvoiceOut(ah $paymentsIn, MoyskladApp $msApp)
    {
        $attributes = $this->user->get('settings.' . AttributeModel::TABLE_NAME, new ah());
        $isAttachedToInvoiceAttr = $attributes->get('paymentin.isAttachedToInvoice')->getAll();

        $msApi = $msApp->getJsonApi();
        $invoicesOut = $msApi->getEntityRows('invoiceout', [
            'expand' => 'organizationAccount, agent'
        ]);

        //Получаем все неоплаченные счета
        $invoicesOut = (new ah($invoicesOut))->filter(function ($item) {
//            return (int)$item['sum'] !== (int)$item['payedSum'] * 100;
            return (int)$item['sum'] > (int)($item['payedSum'] * 100);
        })->getAll();

        $updatePayment = [];
        $updateInvoiceOut = [];
        $paymentsIn->each(function($payment) use (
            $invoicesOut,
            &$updatePayment,
            &$updateInvoiceOut,
            &$isAttachedToInvoiceAttr
        ) {
            if (empty($payment['organizationAccount']['meta']['href']) || empty($payment['paymentPurpose'])) {
                return;
            }

            foreach ($invoicesOut as &$invoiceOut) {
//                $arr = new ah($invoiceOut); Поменял имя переменной на более понятную
                $invoiceFieldsArr = new ah($invoiceOut);

                // Добавил массив для проверки соответствия ключевых сущностей
                $entitiesToMatch = [
                    'agent',
                    'organizationAccount',
                    'organization'
                ];

//                if (empty($arr['organizationAccount']['meta']['href'])) {
//                    continue;
//                }
//
//                $notEqualAgent = !TextHelper::isEqual($arr['agent']['meta']['href'], $payment['agent']['meta']['href']);
//                $notEqualAccount = !TextHelper::isEqual($arr['organizationAccount']['meta']['href'], $payment['organizationAccount']['meta']['href']);
//                $notEqualOrganization = !TextHelper::isEqual($arr['organization']['meta']['href'], $payment['organization']['meta']['href']);
//
//                if ($notEqualAgent || $notEqualAccount || $notEqualOrganization) {
//                    continue;
//                }

                //Закомментированный выше код вынес в отдельную функцию shouldSkipInvoice

//                // найти номер счета в назначении платежа
//                $attachedByPurpose = false;
//                if (strpos($payment['paymentPurpose'], $arr['name']) !== false
//                    || ((int)$arr['name'] !== 0 && strpos($payment['paymentPurpose'], (string)(int)$arr['name']) !== false)) {
//                    $attachedByPurpose = self::invoiceNumberInPurpose($arr['name'], $payment['paymentPurpose']);
//                }
//
//                // найти дату выставления счета в назначении платежа
//                if (!$attachedByPurpose && $arr['sum'] == $payment['sum']) {
//                    $prepareDate = date('d.m.Y', strtotime($arr['moment']));
//                    $attachedByPurpose = strpos($payment['paymentPurpose'], $prepareDate) !== false;
//                }

                /*
                 * Проблема в закоментированном выше коде
                 * В методе invoiceNumberInPurpose происходит разбивка строки назначения на числа и проверка, есть ли там номер счета.
                 * Но если номер счета в назначении не совпадает с именем счета (name), то привязка не происходит.
                 * Тогда срабатывает следующая проверка на дату и сумму, если сумма и дата совпадают происходит привязка.
                 * Здесь не учтен вариант когда в одну дату могут быть выставленны разные счета на одну и ту же сумму.
                 * То есть проигноривав проверку номера счета происходит проверка суммы и даты которрая привязывает счет к первому найденному
                 *  при несколькольких счетах с одной и той же суммой и датой привязка будет всегда к одному, верхнему в списке.
                 *
                 * Необходио делать проверку по номеру счета и дате (по опыту знаю что в бухалтерии могут быть счета с одинаковыми номерами от разных дат),
                 *  с учетом расширения функционала добавлением поддержки частичных платежей нет необходимости проверять сумму.
                 * Проверку номера счета и даты вынес в отдельную функцию invoiceNumberAndDateInPurpose.
                 * Теперь сначала проверяются соответствия ключевых сущностей
                 *  затем соответствия номера и даты, если проверка не пройдена на любом этапе -> переход к следующей итерации
                 */

//                if (!$attachedByPurpose && $arr['sum'] != $payment['sum']) {
//                    continue;
//                }
                $prepareDate = date('d.m.Y', strtotime($invoiceFieldsArr['moment']));

                if (self::shouldSkipInvoice($invoiceFieldsArr, $payment, $entitiesToMatch) ||
                    self::invoiceNumberAndDateInPurpose($invoiceFieldsArr['name'], $payment['paymentPurpose'], $prepareDate))
                    continue;

                //Дошли сюда значит выбран нужный счет
                $isAttachedToInvoiceAttr['value'] = true;
                $payment['attributes'] = [$isAttachedToInvoiceAttr];
                $payment['operations'] = [['meta' => $invoiceOut['meta']]];
                $updatePayment[] = $payment;

                //Добавлю возможность записи нескольких платежей и запишу частичный платеж в поле счета payedSum
                $invoiceOut['payedSum'] += $payment['sum'];
//                $invoiceOut['payments'] = [['meta' => $payment['meta']]];
                $invoiceOut['payments'] ??= [];
                $invoiceOut['payments'][] = ['meta' => $payment['meta']];

                $updateInvoiceOut[] = $invoiceOut;

                return;
            }
        });

        if (!empty($updatePayment)) {
            $msApi->sendEntity('paymentin', $updatePayment);
        }

        if (!empty($updateInvoiceOut)) {
            $msApi->sendEntity('invoiceout', $updateInvoiceOut);
        }
    }

    //Так как функция наследуемая не стал убирать,
    // возможно в наследуемом классе нужно будет внести изменения для использования функции invoiceNumberAndDateInPurpose
    /**
     * @param $invoiceName
     * @param $paymentPurpose
     *
     * @return bool
     */
    protected static function invoiceNumberInPurpose($invoiceName, $paymentPurpose): bool
    {
        $prepareStr = preg_replace('/\D/', ' ', $paymentPurpose);
        $prepareStr = preg_replace('/\s+/', ' ', $prepareStr);

        $ppAr = explode(' ', $prepareStr);
        foreach ($ppAr as $piece) {
            if ($piece == $invoiceName) {
                return true;
            }
        }
        return false;
    }


    /**
     * @param $invoiceName
     * @param string $prepareDate
     * @param string $paymentPurpose
     *
     * @return bool
     */
    protected static function invoiceNumberAndDateInPurpose($invoiceName, string $paymentPurpose, string $prepareDate): bool
    {
        $invoiceNumberAndDate = self::extractNumbersAndDateFromString($paymentPurpose);
        if (is_null($invoiceNumberAndDate)) return false;

        return !($invoiceName == $invoiceNumberAndDate['number'] && $prepareDate == $invoiceNumberAndDate['row_date']);
    }

    private static function extractNumbersAndDateFromString(string $str): ?array
    {
        //Если в дате могут быть слова обозначающие месяц, например 19 февраля 2025,
        // необходимо добавлять логику, изменять патерн парсинга, пока исхожу из того что дата состоит только из цифр
        $pattern = '/
        (?:^|\s)                     # Начало строки или пробел
        (?:сч|сч[её]т|с\/ф|сч\.)\D*  # Префикс номера
        (\d+)                         # Номер счета (группа 1)
        .*?                           # Любые символы
        (?:                          # Варианты разделителей даты
          от\s*|                     # "от" с пробелами
          \bна\s*|                   # "на"
          \/|                        # Слеш
          \|                         # Обратный слеш
          ,\s*                       # Запятая
        )
        \s*                          # Пробелы
        (\d{2}[.\/-]\d{2}[.\/-]\d{4}) # Дата (группа 2)
    /uxi';

        if (!preg_match($pattern, $str, $matches)) {
            return null; //Необходима дополнительная обработка ошибки парсинга и логирование
        }

        // Нормализация даты
        $dateStr = str_replace(['/', '-'], '.', $matches[2]);

        return [
            'number' => $matches[1],
            'row_date' => $dateStr,
        ];
    }

    private static function shouldSkipInvoice($invoice, array $payment, array $entitiesToMatch): bool
    {
        // 1. Проверка обязательного поля organizationAccount
        if (empty($invoice['organizationAccount']['meta']['href'])) {
            return true;
        }

        // 2. Проверка соответствия ключевых сущностей
        foreach ($entitiesToMatch as $entity) {

            if (!TextHelper::isEqual($invoice[$entity]['meta']['href'], $payment[$entity]['meta']['href'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Остальные методы класса. Для решения задачи они не нужны.
     */
}