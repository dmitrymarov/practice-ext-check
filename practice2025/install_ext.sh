#!/bin/bash

EXTENSIONS_DIR="./extensions"

# Создаем директорию для расширений, если её нет
mkdir -p "$EXTENSIONS_DIR"
cd "$EXTENSIONS_DIR"

echo "=== Установка расширений MediaWiki ==="

# Функция для безопасного клонирования репозитория
clone_extension() {
    local name=$1
    local url=$2
    local branch=${3:-"master"}
    
    if [ -d "$name" ]; then
        echo "✓ $name уже установлен, обновляем..."
        cd "$name"
        git pull
        cd ..
    else
        echo "→ Клонирование $name..."
        git clone --depth 1 --branch "$branch" "$url" "$name"
    fi
}

# Semantic MediaWiki и связанные расширения
echo -e "\n### Semantic MediaWiki расширения ###"
clone_extension "SemanticMediaWiki" "https://github.com/SemanticMediaWiki/SemanticMediaWiki.git" "master"
clone_extension "SemanticResultFormats" "https://github.com/SemanticMediaWiki/SemanticResultFormats.git" "master"
clone_extension "SemanticDrilldown" "https://github.com/SemanticMediaWiki/SemanticDrilldown.git" "master"
clone_extension "SemanticWatchlist" "https://github.com/SemanticMediaWiki/SemanticWatchlist.git" "master"
clone_extension "SemanticTasks" "https://github.com/SemanticMediaWiki/SemanticTasks.git" "master"
clone_extension "SemanticCite" "https://github.com/SemanticMediaWiki/SemanticCite.git" "master"
clone_extension "SemanticGlossary" "https://github.com/SemanticMediaWiki/SemanticGlossary.git" "master"
clone_extension "Validator" "https://github.com/JeroenDeDauw/Validator.git" "master"

# LDAP расширения (для будущего использования)
echo -e "\n### LDAP расширения ###"
clone_extension "LDAPProvider" "https://github.com/wikimedia/mediawiki-extensions-LDAPProvider.git" "REL1_39"
clone_extension "LDAPAuthentication2" "https://github.com/wikimedia/mediawiki-extensions-LDAPAuthentication2.git" "REL1_39"
clone_extension "LDAPAuthorization" "https://github.com/wikimedia/mediawiki-extensions-LDAPAuthorization.git" "REL1_39"
clone_extension "LDAPGroups" "https://github.com/wikimedia/mediawiki-extensions-LDAPGroups.git" "REL1_39"
clone_extension "LDAPUserInfo" "https://github.com/wikimedia/mediawiki-extensions-LDAPUserInfo.git" "REL1_39"
clone_extension "LDAPSyncAll" "https://github.com/wikimedia/mediawiki-extensions-LDAPSyncAll.git" "REL1_39"
clone_extension "PluggableAuth" "https://github.com/wikimedia/mediawiki-extensions-PluggableAuth.git" "REL1_39"

# Расширения от lvefunc
echo -e "\n### Расширения от lvefunc ###"
clone_extension "MiniORM" "https://github.com/lvefunc/MiniORM.git" "master"
clone_extension "Workflows" "https://github.com/lvefunc/Workflows.git" "master"
clone_extension "Review" "https://github.com/lvefunc/Review.git" "master"

# Другие расширения
echo -e "\n### Другие расширения ###"
clone_extension "CategoryTree" "https://github.com/wikimedia/mediawiki-extensions-CategoryTree.git" "REL1_39"
clone_extension "InputBox" "https://github.com/wikimedia/mediawiki-extensions-InputBox.git" "REL1_39"
clone_extension "PageForms" "https://github.com/wikimedia/mediawiki-extensions-PageForms.git" "REL1_39"
clone_extension "CodeMirror" "https://github.com/wikimedia/mediawiki-extensions-CodeMirror.git" "REL1_39"
clone_extension "EmbedVideo" "https://github.com/StarCitizenWiki/mediawiki-extensions-EmbedVideo.git" "master"
clone_extension "Cognitive Process Designer" "https://github.com/wikimedia/mediawiki-extensions-CognitiveProcessDesigner" "REL1_39"
echo -e "\n### Проверка установленных расширений ###"
for ext in SupportSystem Elastica CirrusSearch SyntaxHighlight_GeSHi AdvancedSearch Echo PdfHandler TimedMediaHandler VisualEditor; do
    if [ -d "$ext" ]; then
        echo "✓ $ext уже установлен"
    else
        echo "✗ $ext отсутствует (может потребоваться ручная установка)"
    fi
done

echo -e "\n=== Установка завершена ==="
echo "Не забудьте:"
echo "1. Запустить 'composer install' в директориях расширений, требующих Composer"
echo "2. Запустить update.php после обновления LocalSettings.php"
echo "3. Проверить права доступа к директориям"

cd ..