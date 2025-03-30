<?php
/**
 * Aliases for special pages
 */

$specialPageAliases = [];

/** English (English) */
$specialPageAliases['en'] = [
    'DecisionTree' => ['DecisionTree', 'Decision_Tree', 'SupportDialog'],
    'SearchSolutions' => ['SearchSolutions', 'Search_Solutions', 'SupportSearch'],
    'ServiceDesk' => ['ServiceDesk', 'Service_Desk', 'SupportTickets', 'Tickets'],
];

/** Russian (Русский) */
$specialPageAliases['ru'] = [
    'DecisionTree' => ['ДеревоРешений', 'Дерево_решений', 'ДиалогПоддержки'],
    'SearchSolutions' => ['ПоискРешений', 'Поиск_решений', 'ПоискПоддержки'],
    'ServiceDesk' => ['СлужбаПоддержки', 'Служба_поддержки', 'ЗаявкиПоддержки', 'Заявки'],
];