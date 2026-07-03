from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

from docx import Document
from docx.shared import Inches


@dataclass(frozen=True)
class HeadingSpec:
    level: int  # 1/2/3
    text: str


def set_paragraph_text_preserve_format(paragraph, text: str) -> None:
    """Replace visible paragraph text while preserving the first run's formatting.

    This is crucial for matching the template's font/size/bold settings exactly.
    """

    runs = list(paragraph.runs)
    if not runs:
        paragraph.add_run(text)
        return

    runs[0].text = text
    for r in runs[1:]:
        r.text = ""


def replace_cover_and_toc(doc: Document, software_name_cn: str) -> None:
    # The sample's first few paragraphs are TOC entries (toc 1).
    # We'll replace the first two toc 1 paragraphs with new title lines.
    toc1 = [p for p in doc.paragraphs if p.style and p.style.name == 'toc 1']
    if len(toc1) >= 2:
        # Keep the template's blank leading toc1 paragraphs intact to preserve layout.
        # The sample's visible cover lines are toc1[4] and toc1[5].
        if len(toc1) >= 6:
            set_paragraph_text_preserve_format(toc1[4], software_name_cn)
            set_paragraph_text_preserve_format(toc1[5], '用户手册')
        else:
            set_paragraph_text_preserve_format(toc1[0], software_name_cn)
            set_paragraph_text_preserve_format(toc1[1], '用户手册')

    # Also replace the first Normal paragraph that is the visible cover title.
    for p in doc.paragraphs:
        if p.style and p.style.name == 'Normal' and p.text.strip() == '用户手册':
            # Keep it as '用户手册' but leave; the line above in toc already sets.
            break

    # Replace any occurrence of old product name in toc/cover area.
    for p in doc.paragraphs[:53]:
        if 'Treasury' in p.text or 'treasury' in p.text:
            set_paragraph_text_preserve_format(p, software_name_cn)


def find_body_start(doc: Document) -> int:
    for i, p in enumerate(doc.paragraphs):
        if p.style and p.style.name == 'Heading 1':
            return i
    raise RuntimeError('Heading 1 not found in template')


def body_template_paragraphs(doc: Document) -> list:
    start = find_body_start(doc)
    return doc.paragraphs[start:]


def build_new_body_like_template(software_en: str, version: str) -> list[str]:
    """Return non-empty body paragraph texts in the exact same count/order as the template.

    We keep the template's style + numbering quirks (including '=4.2输入') by only
    replacing the paragraph text, not the structure.
    """

    items: list[str] = []
    # 1 引言
    # Matches template: Heading1, Heading2, Normal, Heading2, Normal, Normal, Heading2, Normal, Normal
    items += [
        '1引言',
        '1.1编写目的',
        f'{software_en} 是运行于 WordPress/WooCommerce 的支付网关插件（版本 {version}），用于在电商收银台提供 Wonder Payments 支付方式，并支持订单状态回传、手动同步及退款等能力。',
        '1.2背景',
        '随着电商业务线上化的发展，商户对多支付方式接入、对账与自动化订单状态处理的需求不断增加。本软件通过 WooCommerce 网关机制对接 Wonder Payments，降低接入成本，提高收款与退款处理效率。',
        '本软件通过插件化方式集成第三方支付接口与 Webhook 回调机制，支持在 WooCommerce 订单流转中自动更新支付状态并保留必要的审计记录。',
        '1.3定义',
        'WooCommerce：WordPress 电商插件框架，用于商品、订单与支付的管理。',
        '支付网关/插件：在结算页为订单提供支付方式并对接第三方支付平台的组件。',
    ]

    # 2 用途
    # Matches template section 2: Heading1, Heading2, Normal x4, Heading2, Heading3, Normal x2, Heading3, Normal x2, Heading3, Normal x2, Heading2, Normal x3
    items += [
        '2用途',
        '2.1功能',
        '网关接入：在 WooCommerce 结算页展示 Wonder Payments 支付方式并生成支付订单/支付链接。',
        '状态回传：接收并校验 Webhook 回调，自动更新 WooCommerce 订单支付状态与订单备注信息。',
        '订单同步：提供后台按钮以便在 Webhook 延迟时手动同步第三方订单状态。',
        '退款处理：支持全额/部分退款，并将退款结果同步到 WooCommerce 订单状态。',
        '2.2性能',
        '2.2.1精度',
        '金额与币种以 WooCommerce 订单为准；与第三方交互的金额精度遵循接口约定。',
        '退款金额校验：不允许超过可退金额，避免超额退款。',
        '2.2.2时间特性',
        '订单状态更新：依赖 Webhook 到达与站点处理速度，正常情况下可实现秒级到分钟级更新。',
        '手动同步：当 Webhook 异常时可由管理员触发同步获取最新状态。',
        '2.2.3灵活性',
        '环境切换：支持生产/测试环境选择。',
        '网关配置：支持标题、描述、到期天数等可配置参数。',
        '2.3安全保密',
        'Webhook 校验：对请求方法、URI、签名等进行必要校验，拒绝非法请求。',
        '密钥保护：对密钥类输入进行过滤并安全存储，避免在页面明文泄露。',
        '日志审计：关键操作与异常会写入日志，便于排障与审计。',
    ]

    # 3 运行环境
    # Matches template section 3: Heading1, Heading2, Normal x2, Heading2, Normal x5
    items += [
        '3运行环境',
        '3.1硬设备',
        '普通云服务器/虚拟主机即可满足运行需求；建议具备稳定公网访问能力以接收 Webhook 回调。',
        '建议开启 HTTPS 并配置固定域名，以保证第三方回调与支付跳转的稳定性。',
        '3.2支持软件',
        'WordPress 5.8 及以上。',
        'WooCommerce 插件（必需）。',
        'PHP 7.4 及以上。',
        'Web 服务：Nginx/Apache 等均可。',
    ]

    # 4 使用过程
    # Matches template section 4: Heading1, Heading2, Normal x3, Heading2 (=4.2输入), Normal, Heading3, Normal x3, Heading3, Normal x3, Heading2, Heading3, Normal x2
    items += [
        '4使用过程',
        '4.1安装与初始化',
        '上传插件到 /wp-content/plugins/wonder-payment-for-woocommerce 或通过后台“插件”上传安装。',
        '在 WordPress 后台启用插件后，进入 WooCommerce → 设置 → 付款，找到 Wonder Payments 并点击“管理”。',
        '按向导扫码登录并选择门店（Business），生成/配置 App ID 与 Webhook 相关配置后保存。',
        '=4.2输入',
        '配置网关所需的商户信息与参数（App ID、环境、密钥等），并确保回调地址可被公网访问。',
        '4.2.1输入数据的现实背景',
        '商户在后台配置第三方支付凭据，用于在下单与退款时调用 Wonder Payments 接口。',
        '在支付完成后第三方通过 Webhook 回调通知站点更新状态。',
        '当 Webhook 延迟时可在订单详情页手动触发同步以获取最新状态。',
        '4.2.2输入格式',
        '配置项以 WooCommerce 支付网关设置表单输入为主。',
        'App ID：字符串，由商户在 Wonder 平台生成并配置到插件。',
        '密钥/签名参数：可能为多行文本（例如 PEM），按页面提示填写。',
        '4.3输出对每项输出作出说明',
        '4.3.1输出数据的现实背景',
        'WooCommerce 订单会记录第三方订单号/交易信息，并在回调到达后自动更新状态。',
        '导出的订单数据可用于对账与经营分析（以 WooCommerce 导出能力为准）。',
    ]

    return items


def apply_body_to_template(doc: Document, items: list[tuple[str, str]]) -> None:
    start = find_body_start(doc)
    # The template already contains one "用户手册" Normal paragraph right before the first Heading 1.
    # Our generated items also start with "用户手册", which would duplicate it.
    if start > 0:
        prev = doc.paragraphs[start - 1]
        if prev.style and prev.style.name == "Normal" and prev.text.strip() == "用户手册":
            if items and items[0][0] == "Normal" and items[0][1].strip() == "用户手册":
                items = items[1:]

    # Ensure enough paragraphs exist for body items.
    needed = len(items)
    existing = len(doc.paragraphs) - start
    if existing < needed:
        for _ in range(needed - existing):
            doc.add_paragraph('', style='Normal')

    # Apply items
    for i, (style, text) in enumerate(items):
        p = doc.paragraphs[start + i]
        # set style to match template styles
        p.style = style
        set_paragraph_text_preserve_format(p, text)

    # Remove any remaining old body paragraphs beyond needed
    end_index = start + needed
    if len(doc.paragraphs) > end_index:
        body = doc._element.body
        for p in list(doc.paragraphs)[end_index:][::-1]:
            body.remove(p._element)


def main() -> None:
    template_path = Path(os.environ['TEMPLATE_PATH']).resolve()
    out_path = Path(os.environ['OUT_PATH']).resolve()

    software_en = os.environ.get('SOFTWARE_EN', 'Wonder Payment For WooCommerce')
    software_cn = os.environ.get('SOFTWARE_CN', 'Wonder Payment For WooCommerce（WooCommerce 支付网关插件）')
    version = os.environ.get('SOFTWARE_VERSION', '1.0.4')

    doc = Document(str(template_path))

    replace_cover_and_toc(doc, software_cn)

    # Rebuild body by following the template's non-empty body paragraph pattern.
    start = find_body_start(doc)
    template_nonempty_indices = [i for i in range(start, len(doc.paragraphs)) if doc.paragraphs[i].text.strip()]
    new_texts = build_new_body_like_template(software_en=software_en, version=version)
    if len(new_texts) != len(template_nonempty_indices):
        raise RuntimeError(f'Body template mismatch: template non-empty={len(template_nonempty_indices)}, new_texts={len(new_texts)}')

    for idx, text in zip(template_nonempty_indices, new_texts):
        set_paragraph_text_preserve_format(doc.paragraphs[idx], text)

    doc.save(str(out_path))


if __name__ == '__main__':
    main()
