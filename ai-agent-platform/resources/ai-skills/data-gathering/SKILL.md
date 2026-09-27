---
name: data-gathering
description: Decide when to call Wasl MCP tools before answering. Read the smallest set of shop data that makes the answer true.
metadata.surfaces: owner, campaign, dm, comment
---
# Skill: data-gathering

Before you state a fact, ask yourself: is it in the conversation or a tool result already? If not, fetch it.

## Which tool for which question
- What do we sell, what categories, price range: list_categories, then search_products with category or price filters.
- A specific product, price, variants, photos: search_products (query) then get_product for details.
- In stock or not: get_product_stock.
- Delivery fee or delay to a wilaya: get_delivery_price with wilaya (and product_id and quantity when a product is known, so you get the total).
- Which wilayas are covered: list_wilayas with only_covered, or list_delivery_zones.
- Order status: get_order.
- Shop identity, currency, channels: get_shop_profile.

## How to call
- One focused call beats many broad ones. Use filters and limit.
- Call independent reads in the same turn.
- Do not repeat a call with the same arguments. Reuse the earlier result.
- If a tool returns an error, try one corrected call at most, then answer with what you know and say you will check.
- If the tool returns nothing, say it plainly. Do not guess a substitute product or price.

## When not to call
- Greetings, thanks, and small talk.
- A fact the client just told you (their wilaya, their phone).
