import re, json

# Simulate the actual LLM response from the API test
text = r'''Here are some practice questions on e-commerce to test your understanding.

```json
{"quiz":{"title":"Practice Quiz: E-Commerce","questions":[{"type":"objective","question":"What is the first step in the online purchasing process?","options":["Select a product","Register on the website","Compare prices","Make a payment"],"correct":1,"explanation":"The first step is to register on the website before selecting products."},{"type":"truefalse","question":"Prices in e-commerce can sometimes be negotiated.","options":["True","False"],"correct":0,"explanation":"True, prices in e-commerce can sometimes be negotiated or discounted."},{"type":"fill_in","question":"The process of buying goods online typically starts with ___ on the website.","correct":"registration","explanation":"Registration is the initial step required to begin the online purchasing process."},{"type":"objective","question":"Which of the following is NOT a method of payment in e-commerce?","options":["Credit card","PayPal","Cash on delivery","Bank transfer"],"correct":2,"explanation":"Cash on delivery is typically not a method used in e-commerce, as payments are usually made online."},{"type":"theoretical","question":"Explain the steps involved in the online purchasing process in your own words.","correct":"1. Register on the website 2. Browse the catalog 3. Compare prices 4. Select products 5. Check out and make payment","answer_hint":"Think about the sequence of actions a customer takes.","explanation":"The online purchasing process involves registering on the website, browsing the catalog, comparing prices, selecting products, and finally checking out to make a payment."}]}}
```
'''

_QUIZ_JSON_PATTERN = re.compile(r"```(?:json)?\s*(\{.*?\"quiz\"\s*:.*?\})\s*```", re.DOTALL)
_QUIZ_BARE_PATTERN = re.compile(r'(\{[\s\S]*?"quiz"\s*:[\s\S]*?"questions"\s*:\s*\[[\s\S]*?\]\s*\})', re.DOTALL)

# Test pattern 1
m1 = _QUIZ_JSON_PATTERN.search(text)
print("Pattern 1 match:", bool(m1))
if m1:
    raw = m1.group(1)
    print("  Captured length:", len(raw))
    try:
        data = json.loads(raw)
        print("  JSON parse: OK, quiz questions:", len(data.get("quiz", {}).get("questions", [])))
    except json.JSONDecodeError as e:
        print("  JSON parse FAILED:", e)
        print("  Raw (first 200):", raw[:200])

# Test pattern 2
m2 = _QUIZ_BARE_PATTERN.search(text)
print("\nPattern 2 match:", bool(m2))
if m2:
    raw = m2.group(1)
    print("  Captured length:", len(raw))
    try:
        data = json.loads(raw)
        print("  JSON parse: OK, quiz questions:", len(data.get("quiz", {}).get("questions", [])))
    except json.JSONDecodeError as e:
        print("  JSON parse FAILED:", e)
        print("  Raw (first 200):", raw[:200])
