<?php
// Файл: fix-metastore-index.php

/**
 * Прямой патч для исправления параметра include_type_name в MetaStoreIndex
 */

// Путь к файлу MetaStoreIndex.php
$metaStoreIndexPath = __DIR__ . '/extensions/CirrusSearch/includes/MetaStore/MetaStoreIndex.php';

// Создаем резервную копию
$backupPath = $metaStoreIndexPath . '.bak';
if (!file_exists($backupPath)) {
    copy($metaStoreIndexPath, $backupPath);
    echo "Создана резервная копия: $backupPath\n";
}

// Читаем файл
$content = file_get_contents($metaStoreIndexPath);

// Ищем конкретный проблемный код в методе createNewIndex
$pattern = '/\$index->request\(\s*\'\',\s*\\\\Elastica\\\\Request::PUT,\s*\$this->buildIndexConfiguration\(\),\s*\[\s*\'master_timeout\'\s*=>\s*\$this->getMasterTimeout\(\),\s*\'include_type_name\'\s*=>\s*\'false\'\s*\]\s*\);/s';

$replacement = '$index->request(
			\'\',
			\\Elastica\\Request::PUT,
			$this->buildIndexConfiguration(),
			[
				\'master_timeout\' => $this->getMasterTimeout()
				// Параметр include_type_name удален для совместимости с OpenSearch
			]
		);';

// Заменяем код
$newContent = preg_replace($pattern, $replacement, $content);

// Сохраняем изменения
if (file_put_contents($metaStoreIndexPath, $newContent)) {
    echo "Патч успешно применен к MetaStoreIndex.php\n";
} else {
    echo "Ошибка при сохранении файла\n";
}

echo "Завершено. Теперь запустите команду UpdateSearchIndexConfig.php снова.\n";