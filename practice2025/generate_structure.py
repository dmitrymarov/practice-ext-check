import os
import sys
import tempfile

# Директории и файлы для исключения
EXCLUDE_DIRS = {".git", ".github", "__pycache__", "venv", "node_modules"}
EXCLUDE_FILES = {".gitignore", "README.md", "STRUCTURE.md"}

# Максимальная глубина вложенности
MAX_DEPTH = 3

def generate_tree(directory, prefix="", level=1):
    """Рекурсивно формирует список файлов и папок в виде Markdown,
    ограничивая вложенность до MAX_DEPTH уровней."""
    if level > MAX_DEPTH:
        return ""

    if not os.path.exists(directory):
        return "⚠ Указанная папка не существует!\n"

    entries = sorted(os.listdir(directory))
    entries = [e for e in entries if e not in EXCLUDE_FILES]
    tree_md = ""

    for index, entry in enumerate(entries):
        path = os.path.join(directory, entry)
        is_last = index == len(entries) - 1
        connector = "└── " if is_last else "├── "

        if os.path.isdir(path) and entry not in EXCLUDE_DIRS:
            tree_md += f"{prefix}{connector} **{entry}/**\n"
            indent = "    " if is_last else "│   "
            tree_md += generate_tree(path, prefix + indent, level + 1)
        elif os.path.isfile(path):
            tree_md += f"{prefix}{connector} {entry}\n"

    return tree_md


def save_structure():
    """Запрашивает у пользователя путь к папке и создает STRUCTURE.md."""
    root_dir = input("Введите путь к папке проекта: ").strip()

    if not os.path.exists(root_dir):
        print("❌ Ошибка: указанная папка не существует.")
        return

    # Генерация дерева с учётом глубины и корректное включение Markdown-блока
    tree = f"# 📂 Структура проекта (до {MAX_DEPTH} уровней)\n\n```{generate_tree(root_dir, level=1)}```\n"

    output_file = os.path.join(root_dir, "STRUCTURE.md")
    with open(output_file, "w", encoding="utf-8") as f:
        f.write(tree)

    print(f"✅ Файл STRUCTURE.md создан в папке: {root_dir}")


def _run_tests():
    """Набор базовых тестов для проверки функций скрипта."""
    # Тест для несуществующей директории
    assert generate_tree("no_such_dir") == "⚠ Указанная папка не существует!\n"

    # Тест для пустой директории
    with tempfile.TemporaryDirectory() as tmp:
        assert generate_tree(tmp) == ""

        # Тест на вывод одного файла
        file_path = os.path.join(tmp, "file.txt")
        open(file_path, "w").close()
        tree = generate_tree(tmp)
        assert "file.txt" in tree

        # Тест на лимит глубины
        base = tmp
        for i in range(MAX_DEPTH + 1):
            base = os.path.join(base, f"dir{i}")
            os.mkdir(base)
        tree = generate_tree(tmp)
        assert f"**dir{MAX_DEPTH - 1}/**" in tree and f"dir{MAX_DEPTH}" not in tree

    print("All tests passed.")


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "test":
        _run_tests()
    else:
        save_structure()
