#!/usr/bin/env python3
"""Извлекает ассеты нового макета из исходного PSD.

PSD весит 133 МБ и в репозиторий не кладётся, поэтому путь к нему передаётся
аргументом. Скрипт нужен, чтобы повторный экспорт после правки макета не
делался руками через Photoshop — и чтобы было видно, какой слой во что
превратился.

    pip install psd-tools pillow
    python3 tools/extract-psd-assets.py "~/Downloads/макнеми тир под сайт.psd"

Ассеты сохраняются в PNG с прозрачностью. WebP здесь не нужен: самый тяжёлый
файл весит 5.5 КБ, выигрыш составил бы пару килобайт, а <img> пришлось бы
оборачивать в <picture> ради браузеров без поддержки формата.
"""

import sys
import os

from psd_tools import PSDImage
from PIL import Image

# Куда складываем. Относительно корня репозитория, а не текущего каталога:
# скрипт зовут и из корня, и из tools/.
ASSETS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "public_html", "assets")

# Логотипы карточек и иконка Telegram — берём конкретные слои, а не группы:
# в группах лежат ещё и полноразмерные текстурные слои на весь холст.
LAYERS = {
    "logo-mk":       "антивор макс/лого антивор/лого/лого макс/Слой 2070",
    "logo-glh":      "антивор галах/лого антивор/Векторный смарт-объект",
    "icon-telegram": "антивор макс/вспомогательные элементы антивор/telegram-logo-telegram-icon-transparent-free-png",
}

# Молния лежит в группе вместе со старым пламенем и скрытым дублем, поэтому
# отбираем её по размеру: 94x86 — единственный слой такой формы.
BOLT_GROUP = "антивор даник/лого антивор"
BOLT_SIZE = (94, 86)

# Аватарки подвала — целые группы: внутри фотография и эллипс-маска, по
# отдельности они бесполезны.
AVATAR_GROUPS = {
    "avatar-discord":   "блок 1 /аватары/аватар дс макса",
    "avatar-tg":        "блок 1 /аватары/аватар тг макса",
    "avatar-bfnews":    "блок 1 /аватары/аватар новости бф",
    "avatar-giveaways": "блок 1 /аватары/аватар новости бф копия",
    "avatar-charlotte": "блок 1 /аватары/аватар шарлотта",
}

# Три знака в шапке — одной картинкой, как сейчас: они всегда показываются
# вместе и никогда по отдельности. В группе лежит ещё и логотип Blox Fruits
# из левого угла — он давно есть в репозитории как bf-logo-trim.png, поэтому
# композит группы обрезаем по трём знакам справа.
HEADER_GROUP = "лого вверх"
HEADER_SKIP = "лого бф копия"


def walk(layers, path=""):
    for layer in layers:
        current = f"{path}/{layer.name}" if path else layer.name
        yield current, layer
        if layer.is_group():
            yield from walk(layer, current)


def find(psd, suffix):
    """Ищет слой по хвосту пути. Полные пути начинаются с 'окак/сам тир
    собственно/...' — повторять эту приставку в конфиге выше незачем."""
    hits = [l for path, l in walk(psd) if path.endswith(suffix)]
    if not hits:
        raise SystemExit(f"не найден слой: {suffix}")
    if len(hits) > 1:
        raise SystemExit(f"неоднозначный путь ({len(hits)} совпадений): {suffix}")
    return hits[0]


def save(image, name):
    if image is None:
        raise SystemExit(f"пустой слой: {name}")
    image = image.convert("RGBA")
    image.save(os.path.normpath(os.path.join(ASSETS, name + ".png")))
    print(f"  {name:18} {image.width}x{image.height}")


def main():
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    psd = PSDImage.open(os.path.expanduser(sys.argv[1]))

    print("логотипы и иконки:")
    for name, path in LAYERS.items():
        save(find(psd, path).composite(), name)

    group = find(psd, BOLT_GROUP)
    bolt = [l for l in group
            if l.visible and l.bbox != (0, 0, 0, 0)
            and (l.bbox[2] - l.bbox[0], l.bbox[3] - l.bbox[1]) == BOLT_SIZE]
    if len(bolt) != 1:
        raise SystemExit(f"молния не опознана: подошло слоёв — {len(bolt)}")
    save(bolt[0].composite(), "logo-bolt")

    print("аватарки подвала:")
    for name, path in AVATAR_GROUPS.items():
        save(find(psd, path).composite(), name)

    print("шапка:")
    header = find(psd, HEADER_GROUP)
    marks = [l for l in header if l.visible and l.name != HEADER_SKIP]
    left = min(l.bbox[0] for l in marks)
    top = min(l.bbox[1] for l in marks)
    right = max(l.bbox[2] for l in marks)
    bottom = max(l.bbox[3] for l in marks)
    ox, oy = header.offset
    save(header.composite().crop((left - ox, top - oy, right - ox, bottom - oy)), "brand-logos")


if __name__ == "__main__":
    main()
