# Website menu vs hamara POS menu (Kashif Food)

14 Oct 2026. Website: `data/menu-api.json` (owner ki di hui menu API) + crawl ki 21 Midnight Deals. POS: prod `kashiffood`, sirf parh kar.
Har website line = ek item ka ek size. Milaan naam se (spelling ke farq theek kar ke); 10 lines haath se jori gayin (andar ki cheezein aur qeemat dekh kar).

| Nateeja | Lines |
| --- | --- |
| Sahi | 231 |
| Qeemat alag | 3 |
| POS me band (inactive) | 0 |
| POS me nahi mila | 1 |
| **Kul website lines** | **235** |
| POS me hain, website par nahi (active, bikne wale) | 42 |

## Samajh (haath se dekh kar)

- **Sizes:** website par ek item ke andar sizes hain (Chicken Biryani: Plain/small/Large/Platter/Family Pack); POS me har size
  **alag product** hai (Chicken Biryani (Sadi), (Small), (Large), (Platter), Family Pack 6 Pcs). Website ka "Plain" = POS ka "Sadi".
  Website "small/Large" = POS "1 Person/2 Person" (Chinese) ya "Small/Large" (Pizza Fries).
- **"With Fries":** POS me har burger/sandwich/crispy ka alag "(With Fries)" product hai (zyada tar Rs 150 mehnga). Website par
  ye alag item nahi; wahan "Add Fries" add-on hai (Pocket Friendly Deal par +150). Neeche "POS me hain, website par nahi" ki
  zyada tar lines yehi hain.
- **Pocket Friendly "+ Fries":** POS deals #70 (900) aur #72 (1,100) = website ke Pocket Friendly Deal 01/02 + "Add Fries +150".
- **Midnight Deal 11** ("Any 1 Pizza Fries With 2 Regular Drinks", 950): POS me do deals hain, #32 Tikka aur #33 Miami Supreme;
  website par customer ek chunta hai.
- **Midnight Deal 17** (Garlic Rice With Shashlik, 1,550): POS ke Midnight deals me nahi. POS me ye sirf aam deal #75 (1,650) hai.
- **Al-Faham:** website ki qeemat (1,600 / 3,100) POS ke do **band** deals (#1, #2) jaisi hai; POS ke chalu products #196/#207
  1,650 / 3,200 par hain.
- **Extra Chicken Piece** (website add-on, +200) = POS product "Extra Chicken Pcs" (#169, 200).
## Qeemat alag

| Website category | Website item | Size | Website Rs | POS item | POS Rs |
| --- | --- | --- | --- | --- | --- |
| Platters | Grill Chicken Al-Fahom | Half | 1,600 | Grill Chicken Al-Faham (Half) (#196) | 1,650 |
| Platters | Grill Chicken Al-Fahom | Full | 3,100 | Grill Chicken Al-Faham (Full) (#207) | 3,200 |
| Beverages | Sting Can |  | 180 | Soft Drink Sting (Can) (#173) | 160 |

## POS me nahi mila

| Website category | Website item | Size | Website Rs | POS item | POS Rs |
| --- | --- | --- | --- | --- | --- |
| Midnight Deals | Midnight Deal 17 |  | 1,550 | - | - |

## Poori list (website ki tarteeb me)

| Website category | Website item | Size | Website Rs | POS item | POS Rs | Nateeja |
| --- | --- | --- | --- | --- | --- | --- |
| Starter | Chicken Hot Wings |  | 990 | Chicken Hot Wings (Spicy) 8 Pcs | 990 | Sahi |
| Starter | Dynamite Chicken |  | 990 | Dynamite Chicken | 990 | Sahi |
| Starter | Dhaka Chicken |  | 990 | Dhaka Chicken | 990 | Sahi |
| Starter | Supreme Nachos |  | 990 | Supreme Nachos | 990 | Sahi |
| Starter | Korean Chicken |  | 990 | Korean Chicken | 990 | Sahi |
| Starter | Fish And Chips |  | 1,490 | Fish And Chips 5 Pcs | 1,490 | Sahi |
| Starter | Dhaka Fish |  | 1,490 | Dhaka Fish 5 Pcs | 1,490 | Sahi |
| Soup | Chicken Corn Soup | Single Serving | 350 | Chicken Corn Soup (Single) | 350 | Sahi |
| Soup | Chicken Corn Soup | Family Bowl | 1,100 | Chicken Corn Soup (Family Bowl) | 1,100 | Sahi |
| Soup | Hot N Sour Soup | Single Serving | 400 | Hot n Sour Soup (Single) | 400 | Sahi |
| Soup | Hot N Sour Soup | Family Bowl | 1,200 | Hot n Sour Soup (Family Bowl) | 1,200 | Sahi |
| Deals | Deal 1 Serve 1 |  | 875 | Deal 1 (Serve 1) (deal) | 875 | Sahi |
| Deals | Deal 2 Serve 2 |  | 1,190 | Deal 2 (Serve 2) (deal) | 1,190 | Sahi |
| Deals | Deal 3 Serve 2 |  | 1,355 | Deal 3 (Serve 2) (deal) | 1,355 | Sahi |
| Deals | Deal 4 Serve 2 |  | 1,420 | Deal 4 (Serve 2) (deal) | 1,420 | Sahi |
| Deals | Deal 5 Serve 2 |  | 1,620 | Deal 5 (Serve 2) (deal) | 1,620 | Sahi |
| Deals | Deal 6 Serve 2 |  | 1,555 | Deal 6 (Serve 2) (deal) | 1,555 | Sahi |
| Deals | Deal 7 Serve 2 |  | 1,985 | Deal 7 (Serve 2) (deal) | 1,985 | Sahi |
| Deals | Deal 8 Serve 2 |  | 2,005 | Deal 8 (Serve 2) (deal) | 2,005 | Sahi |
| Deals | Deal 9 Serve 2 |  | 1,755 | Deal 9 (Serve 2) (deal) | 1,755 | Sahi |
| Deals | Deal 10 Serve 2 |  | 1,905 | Deal 10 (Serve 2) (deal) | 1,905 | Sahi |
| Deals | Deal 11 Serve 2 |  | 1,490 | Deal 11 (Serve 2) (deal) | 1,490 | Sahi |
| Deals | Deal 12 Serve 2 |  | 1,620 | Deal 12 (Serve 2) (deal) | 1,620 | Sahi |
| Deals | Deal 13 Serve 2 |  | 2,045 | Deal 13 (Serve 2) (deal) | 2,045 | Sahi |
| Deals | Deal 14 Serve 2 |  | 1,390 | Deal 14 (Serve 2) (deal) | 1,390 | Sahi |
| Deals | Deal 15 Serve 2 |  | 2,120 | Deal 15 (Serve 2) (deal) | 2,120 | Sahi |
| Deals | Deal 16 Serve 4 |  | 4,270 | Deal 16 (Serve 4) (deal) | 4,270 | Sahi |
| Deals | Deal 17 Serve 4 |  | 4,355 | Deal 17 (Serve 4) (deal) | 4,355 | Sahi |
| Deals | Deal 18 Serve 4 |  | 3,510 | Deal 18 (Serve 4) (deal) | 3,510 | Sahi |
| Deals | Deal 19 Serve 4 |  | 5,540 | Deal 19 (Serve 4) (deal) | 5,540 | Sahi |
| Deals | Deal 20 Serve 6 |  | 5,625 | Deal 20 (Serve 6) (deal) | 5,625 | Sahi |
| Singaporean Rice | Singaporean Rice | Regular | 600 | Singaporean Rice (Regular) | 600 | Sahi |
| Singaporean Rice | Singaporean Rice | Large | 1,050 | Singaporean Rice (Large) | 1,050 | Sahi |
| Singaporean Rice | Singaporean Rice | Platter | 1,580 | Singaporean Rice (Platter) | 1,580 | Sahi |
| Singaporean Rice | Singaporean Rice | Khaas | 2,900 | Singaporean Rice (Khass) | 2,900 | Sahi |
| Singaporean Rice | Singaporean Rice Family Pack | small | 2,750 | Singaporean Rice Family Pack (Small) | 2,750 | Sahi |
| Singaporean Rice | Singaporean Rice Family Pack | Large | 4,100 | Singaporean Rice Family Pack (Large) | 4,100 | Sahi |
| Singaporean Rice | Singaporean Rice Khass |  | 2,900 | Singaporean Rice (Khass) | 2,900 | Sahi |
| Biryani | Chicken Biryani | Plain | 250 | Chicken Biryani (Sadi) | 250 | Sahi |
| Biryani | Chicken Biryani | small | 400 | Chicken Biryani (Small) | 400 | Sahi |
| Biryani | Chicken Biryani | Large | 750 | Chicken Biryani (Large) | 750 | Sahi |
| Biryani | Chicken Biryani | Platter | 2,000 | Chicken Biryani (Platter) | 2,000 | Sahi |
| Biryani | Chicken Biryani | Family Pack | 2,270 | Chicken Biryani Family Pack 6 Pcs | 2,270 | Sahi |
| Biryani | Chicken biryani Take Away |  | 2,270 | Chicken Biryani Family Pack 6 Pcs | 2,270 | Sahi |
| Barbecue | Chicken Tikka | Leg | 600 | Chicken Tikka (Leg) | 600 | Sahi |
| Barbecue | Chicken Tikka | Chest | 650 | Chicken Tikka (Chest) | 650 | Sahi |
| Barbecue | Chicken Malai Tikka | Leg | 700 | Chicken Malai Tikka (Leg) | 700 | Sahi |
| Barbecue | Chicken Malai Tikka | Chest | 750 | Chicken Malai Tikka (Chest) | 750 | Sahi |
| Barbecue | Chicken Malai Boti |  | 1,150 | Chicken Malai Boti | 1,150 | Sahi |
| Barbecue | Chicken Boti Boneless |  | 1,150 | Chicken Boti Boneless | 1,150 | Sahi |
| Barbecue | Chicken Reshmi Kebab |  | 1,150 | Chicken Reshmi Kebab | 1,150 | Sahi |
| Barbecue | Chicken Shahi Chattakh |  | 1,250 | Chicken Shahi Chattakh | 1,250 | Sahi |
| Barbecue | Chicken Balochi Boti |  | 1,250 | Chicken Baluchi Boti | 1,250 | Sahi |
| Barbecue | Beef Behari Boti |  | 1,250 | Beef Behari Boti | 1,250 | Sahi |
| Barbecue | Beef Seekh Kebab |  | 1,250 | Beef Seekh Kebab | 1,250 | Sahi |
| Barbecue | Beef Dhaga Kebab | Plain | 1,250 | Beef Dhaga Kebab | 1,250 | Sahi |
| Barbecue | Beef Dhaga Kebab | Fry | 1,350 | Beef Dhaga Kebab (Fry) | 1,350 | Sahi |
| Barbecue | Beef Afghani Boti |  | 1,550 | Beef Afghani Boti | 1,550 | Sahi |
| Barbecue | Bar-B-Que Fish Grill | Half | 2,250 | Bar B Q Fish Grill (Half) | 2,250 | Sahi |
| Barbecue | Bar-B-Que Fish Grill | Full | 4,500 | Bar B Q Fish Grill (Full) | 4,500 | Sahi |
| BBQ New Arrival | Cajun Chicken |  | 850 | Cajun Chicken (Spicy) | 850 | Sahi |
| BBQ New Arrival | Chicken Namkeen Boti |  | 850 | Chicken Namkeen Boti | 850 | Sahi |
| BBQ New Arrival | Adana Kebab | Chicken | 1,250 | Adana Kebab Chicken | 1,250 | Sahi |
| BBQ New Arrival | Adana Kebab | Beef | 1,450 | Adana Kebab Beef | 1,450 | Sahi |
| BBQ New Arrival | Tandoori Chicken |  | 1,250 | Tandoori Chicken | 1,250 | Sahi |
| BBQ New Arrival | Moroccan Kebab |  | 1,450 | Morocon Kebab | 1,450 | Sahi |
| Platters | Turkiya kebab |  | 1,800 | Turkiya Kebab (1-2 Persons) (deal) | 1,800 | Sahi |
| Platters | Grill Chicken Al-Fahom | Half | 1,600 | Grill Chicken Al-Faham (Half) | 1,650 | Qeemat alag |
| Platters | Grill Chicken Al-Fahom | Full | 3,100 | Grill Chicken Al-Faham (Full) | 3,200 | Qeemat alag |
| Platters | Classic Platter 1 |  | 5,500 | Classic Platter 1 (6 Persons) (deal) | 5,500 | Sahi |
| Platters | Classic Platter 2 |  | 4,400 | Classic Platter 2 (4 Persons) (deal) | 4,400 | Sahi |
| Platters | Classic Platter 3 |  | 3,300 | Classic Platter 3 (3 Persons) (deal) | 3,300 | Sahi |
| Platters | Chullu Kebab Platter | Chicken | 1,900 | Chullu Kebab Chicken (1-2 Persons) (deal) | 1,900 | Sahi |
| Platters | Chullu Kebab Platter | Beef | 2,000 | Chullu Kebab Beef (1-2 Persons) (deal) | 2,000 | Sahi |
| Platters | Balochi Boti Rice |  | 1,900 | Balochi Boti Rice (1-2 Persons) (deal) | 1,900 | Sahi |
| Platters | Bar-B-Que Platter |  | 5,500 | Bar-B-Que Platter (4-5 Persons) (deal) | 5,500 | Sahi |
| Crispy Fried Chicken | Crispy Fried Chicken | 2 Pcs | 650 | 2 Pcs Crispy Fried Chicken | 650 | Sahi |
| Crispy Fried Chicken | Crispy Fried Chicken | 3 Pcs | 900 | 3 Pcs Crispy Fried Chicken | 900 | Sahi |
| Crispy Fried Chicken | Crispy Spicy Fried Chicken | 2 Pcs | 700 | 2 Pcs Crispy Fried Chicken (Spicy) | 700 | Sahi |
| Crispy Fried Chicken | Crispy Spicy Fried Chicken | 3 Pcs | 950 | 3 Pcs Crispy Fried Chicken (Spicy) | 950 | Sahi |
| Crispy Fried Chicken | Crispy Garlic Fried Chicken |  | 700 | 2 Pcs Crispy Fried Chicken (Garlic) | 700 | Sahi |
| Pocket Friendly Deal | Pocket Friendly Deal 01 |  | 750 | Chicken Crunch Burger + Crispy Fried Chicken (Drum Stick) (deal) | 750 | Sahi |
| Pocket Friendly Deal | Pocket Friendly Deal 02 |  | 950 | Chicken Zinger Burger + Crispy Fried Chicken (Drum Stick) (deal) | 950 | Sahi |
| Family Deals | Family Deal 01 |  | 2,500 | Family Deal 1 - 9 Pcs Crispy Fried Chicken (deal) | 2,500 | Sahi |
| Family Deals | Family Deal 02 |  | 2,750 | Family Deal 2 - 4 Crunch Burger + 4 Pc Chicken + 2 Roll (deal) | 2,750 | Sahi |
| Family Deals | Family Deal 03 |  | 2,950 | Family Deal 3 - 2 Zinger + 2 Crunch + 4 Pc Chicken + 2 Roll (deal) | 2,950 | Sahi |
| Family Deals | Family Deal 04 |  | 3,250 | Family Deal 4 - 4 Zinger + 4 Pc Chicken + 2 Roll (deal) | 3,250 | Sahi |
| Kids House | Nuggets |  | 700 | Nuggets 6 Pcs | 700 | Sahi |
| Kids House | Chicken Crunch Burger |  | 500 | Chicken Crunch Burger | 500 | Sahi |
| Kids House | Hot Shots |  | 650 | Hot Shot 9 Pcs | 650 | Sahi |
| Kids House | Crispy Fried Chicken |  | 450 | Crispy Fried Chicken 1 Pcs | 450 | Sahi |
| Burgers | Chicken Crunch Burger |  | 500 | Chicken Crunch Burger | 500 | Sahi |
| Burgers | Chicken Zinger Burger |  | 650 | Chicken Zinger Burger | 650 | Sahi |
| Burgers | Thrill Zinger Burger |  | 700 | Thrill Zinger Burger (Spicy) | 700 | Sahi |
| Burgers | Chicken Burger |  | 600 | Chicken Burger | 600 | Sahi |
| Burgers | Beef Burger |  | 650 | Beef Burger | 650 | Sahi |
| Sandwiches | Chicken Sandwich | Plain | 700 | Chicken Sandwich | 700 | Sahi |
| Sandwiches | Chicken Sandwich | BBQ | 800 | Chicken Bar-B-Que Sandwich | 800 | Sahi |
| Sandwiches | Chicken Sandwich | Malai Boti | 800 | Chicken Malai Boti Sandwich | 800 | Sahi |
| Sandwiches | Club Sandwich |  | 750 | Club Sandwich | 750 | Sahi |
| Fries | Plain Fries | Regular | 350 | Fries (Regular) | 350 | Sahi |
| Fries | Plain Fries | Large | 450 | Fries (Large) | 450 | Sahi |
| Fries | Masala Fries | Regular | 370 | Fries Masala (Regular) | 370 | Sahi |
| Fries | Masala Fries | Large | 470 | Fries Masala (Large) | 470 | Sahi |
| Fries | Mayo Garlic Fries | Regular | 450 | Mayo Garlic Fries (Regular) | 450 | Sahi |
| Fries | Mayo Garlic Fries | Large | 550 | Mayo Garlic Fries (Large) | 550 | Sahi |
| Fries | Pizza Fries | small | 800 | Tikka Pizza Fries (Small) | 800 | Sahi |
| Fries | Pizza Fries | Large | 1,300 | Tikka Pizza Fries (Large) | 1,300 | Sahi |
| Chinese | BBQ Shashlik with Garlic Fried Rice |  | 1,650 | Garlic Rice With Shashlik Bar-B-Que | 1,650 | Sahi |
| Chinese | Manchurian With Fried Rice | 1 Person | 1,350 | Manchurian With Fried Rice (1 Person) | 1,350 | Sahi |
| Chinese | Manchurian With Fried Rice | 2 Person | 2,450 | Manchurian With Fried Rice (2 Person) | 2,450 | Sahi |
| Chinese | Shashlik With Fried Rice | 1 Person | 1,350 | Shashlik With Fried Rice (1 Person) | 1,350 | Sahi |
| Chinese | Shashlik With Fried Rice | 2 Person | 2,450 | Shashlik With Fried Rice (2 Person) | 2,450 | Sahi |
| Chinese | Schezwan With Fried Rice | 1 Person | 1,350 | Schezwan With Fried Rice (1 Person) | 1,350 | Sahi |
| Chinese | Schezwan With Fried Rice | 2 Person | 2,450 | Schezwan With Fried Rice (2 Person) | 2,450 | Sahi |
| Chinese | Chicken Chilli Dry With Fried Rice | 1 Person | 1,350 | Chicken Chilli Dry With Fried Rice (1 Person) | 1,350 | Sahi |
| Chinese | Chicken Chilli Dry With Fried Rice | 2 Person | 2,450 | Chicken Chilli Dry With Fried Rice (2 Person) | 2,450 | Sahi |
| Chinese | Kung Pao Chicken With Fried Rice | 1 Person | 1,350 | Kung Pao Chicken With Fried Rice (1 Person) | 1,350 | Sahi |
| Chinese | Kung Pao Chicken With Fried Rice | 2 Person | 2,450 | Kung Pao Chicken With Fried Rice (2 Person) | 2,450 | Sahi |
| Chinese | Dragon Chicken With Fried Rice | small | 1,350 | Dragon Chicken With Fried Rice (1 Person) | 1,350 | Sahi |
| Chinese | Dragon Chicken With Fried Rice | Large | 2,450 | Dragon Chicken With Fried Rice (2 Person) | 2,450 | Sahi |
| Chinese | Beef Chilli Dry With Fried Rice | 1 Person | 1,450 | Beef Chilli Dry With Fried Rice (1 Person) | 1,450 | Sahi |
| Chinese | Beef Chilli Dry With Fried Rice | 2 Person | 2,650 | Beef Chilli Dry With Fried Rice (2 Person) | 2,650 | Sahi |
| Chinese | Dragon Beef With Fried Rice | 1 Person | 1,450 | Dragon Beef With Fried Rice (1 Person) | 1,450 | Sahi |
| Chinese | Dragon Beef With Fried Rice | 2 Person | 2,650 | Dragon Beef With Fried Rice (2 Person) | 2,650 | Sahi |
| Chinese | Chicken Fried Rice | 1 Person | 1,200 | Chicken Fried Rice (1 Person) | 1,200 | Sahi |
| Chinese | Chicken Fried Rice | 2 Person | 1,950 | Chicken Fried Rice (2 Person) | 1,950 | Sahi |
| Chinese | Chicken Chow Mein | 1 Person | 1,200 | Chicken Chow Mein (1 Person) | 1,200 | Sahi |
| Chinese | Chicken Chow Mein | 2 Person | 1,900 | Chicken Chow Mein (2 Person) | 1,900 | Sahi |
| Chinese | Vegetable Chow Mein | small | 1,050 | Vegetable Chow Mein (1 Person) | 1,050 | Sahi |
| Chinese | Vegetable Chow Mein | Large | 2,050 | Vegetable Chow Mein (2 Person) | 2,050 | Sahi |
| Chinese | Vegetable Fried Rice | 1 Person | 950 | Vegetable Fried Rice (1 Person) | 950 | Sahi |
| Chinese | Vegetable Fried Rice | 2 Person | 1,750 | Vegetable Fried Rice (2 Person) | 1,750 | Sahi |
| Steaks | Tarragon Steak | Chicken | 2,050 | Tarragon Steak (Chicken) | 2,050 | Sahi |
| Steaks | Tarragon Steak | Beef | 2,650 | Tarragon Steak (Beef) | 2,650 | Sahi |
| Steaks | Mushroom Steak | Chicken | 2,050 | Mushroom Steak (Chicken) | 2,050 | Sahi |
| Steaks | Mushroom Steak | Beef | 2,650 | Mushroom Steak (Beef) | 2,650 | Sahi |
| Steaks | Spicy Mexican Steak | Chicken | 2,050 | Spicy Mexican Steak (Chicken) | 2,050 | Sahi |
| Steaks | Spicy Mexican Steak | Beef | 2,650 | Spicy Mexican Steak (Beef) | 2,650 | Sahi |
| Pasta | Chicken Alfredo Pasta | 1 Person | 1,250 | Chicken Alfredo Pasta (1 Person) | 1,250 | Sahi |
| Pasta | Chicken Alfredo Pasta | 2 Person | 2,150 | Chicken Alfredo Pasta (2 Person) | 2,150 | Sahi |
| Pasta | Chicken Mac n Cheese Pasta | 1 Person | 1,250 | Chicken Mac n Cheese Pasta (1 Person) | 1,250 | Sahi |
| Pasta | Chicken Mac n Cheese Pasta | 2 Person | 2,150 | Chicken Mac n Cheese Pasta (2 Person) | 2,150 | Sahi |
| Pasta | Chicken Red Sauce Pasta | 1 Person | 1,250 | Chicken Red Sauce Pasta (1 Person) | 1,250 | Sahi |
| Pasta | Chicken Red Sauce Pasta | 2 Person | 2,150 | Chicken Red Sauce Pasta (2 Person) | 2,150 | Sahi |
| Pasta | Chicken Parmesan Cheese Pasta | 1 Person | 1,250 | Chicken Parmesan Cheese Pasta (1 Person) | 1,250 | Sahi |
| Pasta | Chicken Parmesan Cheese Pasta | 2 Pcs | 2,150 | Chicken Parmesan Cheese Pasta (2 Person) | 2,150 | Sahi |
| Smash Beef Burgers | American Vintage |  | 1,050 | American Vintage (Beef) | 1,050 | Sahi |
| Smash Beef Burgers | Mushroom Melt |  | 1,050 | Mushroom Melt (Beef) | 1,050 | Sahi |
| Smash Beef Burgers | Smoky Bar-B-Que |  | 1,150 | Smoky Bar-B-Que (Beef) | 1,150 | Sahi |
| Smash Beef Burgers | Crunchy Jalapeno |  | 1,550 | Crunchy Jalapeno (Beef) | 1,550 | Sahi |
| Smash Chicken Burgers | Smoke House |  | 900 | Smoke House (Chicken) | 900 | Sahi |
| Smash Chicken Burgers | Grilled Mushroom |  | 900 | Grilled Mushroom (Chicken) | 900 | Sahi |
| Smash Chicken Burgers | Cheesy Blaze |  | 1,000 | Cheesy Blaze (Chicken) | 1,000 | Sahi |
| Smash Chicken Burgers | Dual Combo Crispy Burger |  | 1,250 | Dual Combo Crispy Burger (Chicken) | 1,250 | Sahi |
| Rolls | Ustad Special Roll | Plain | 550 | Ustad Special Roll | 550 | Sahi |
| Rolls | Ustad Special Roll | Mayo | 600 | Ustad Special Mayo | 600 | Sahi |
| Rolls | Ustad Special Roll | Chipotle | 600 | Ustad Special Chipotle Roll | 600 | Sahi |
| Rolls | Beef Boti Rolls | Plain | 370 | Beef Boti Chatni Roll | 370 | Sahi |
| Rolls | Beef Boti Rolls | Mayo | 400 | Beef Boti Garlic Mayo Roll | 400 | Sahi |
| Rolls | Beef Boti Rolls | Cheese | 410 | Beef Boti Mayo Cheese Roll | 410 | Sahi |
| Rolls | Beef Kebab Rolls | Plain | 370 | Beef Seekh Kebab Chatni Roll | 370 | Sahi |
| Rolls | Beef Kebab Rolls | Mayo | 400 | Beef Kebab Garlic Mayo Roll | 400 | Sahi |
| Rolls | Beef Kebab Rolls | Cheese | 410 | Beef Kebab Mayo Cheese Roll | 410 | Sahi |
| Rolls | Chicken Balochi Roll | Plain | 400 | Chicken Balochi Roll | 400 | Sahi |
| Rolls | Chicken Balochi Roll | Mayo | 440 | Chicken Balochi Mayo Roll | 440 | Sahi |
| Rolls | Chicken Balochi Roll | Cheese | 450 | Chicken Balochi Cheese Roll | 450 | Sahi |
| Rolls | Chicken Chatni Roll | Plain | 370 | Chicken Chatni Roll | 370 | Sahi |
| Rolls | Chicken Chatni Roll | Mayo | 400 | Chicken Garlic Mayo Roll | 400 | Sahi |
| Rolls | Chicken Chatni Roll | Cheese | 410 | Chicken Mayo Cheese Roll | 410 | Sahi |
| Rolls | Chicken Crispy Rolls | Plain | 500 | Chicken Crispy Chatni Roll | 500 | Sahi |
| Rolls | Chicken Crispy Rolls | Mayo | 500 | Chicken Crispy Mayo Garlic Roll | 500 | Sahi |
| Rolls | Chicken Crispy Rolls | Cheese | 510 | Chicken Crispy Mayo Cheese Roll | 510 | Sahi |
| Rolls | Chicken Malai Boti Roll | Plain | 390 | Chicken Malai Chatni Roll | 390 | Sahi |
| Rolls | Chicken Malai Boti Roll | Mayo | 420 | Chicken Malai Garlic Mayo Roll | 420 | Sahi |
| Rolls | Chicken Malai Boti Roll | Cheese | 440 | Chicken Malai Mayo Cheese Roll | 440 | Sahi |
| Rolls | Chicken Reshmi Kebab Roll | Plain | 380 | Chicken Reshmi Kebab Chatni Roll | 380 | Sahi |
| Rolls | Chicken Reshmi Kebab Roll | Mayo | 400 | Chicken Reshmi Kebab Garlic Mayo Roll | 400 | Sahi |
| Rolls | Chicken Reshmi Kebab Roll | Cheese | 420 | Chicken Reshmi Kebab Mayo Cheese Roll | 420 | Sahi |
| Meals | Meal 01 |  | 1,700 | 5 Pcs Crispy Fried Chicken + Fries (deal) | 1,700 | Sahi |
| Meals | Meal 02 |  | 2,000 | 2 Chicken Zinger Burger + 2 Pcs Crispy Fried Chicken + Fries (deal) | 2,000 | Sahi |
| Beverages | Soft Drink | 500 ml | 140 | Soft Drink (500 ml) | 140 | Sahi |
| Beverages | Soft Drink | 1.5 ltr | 220 | Soft Drink (1.5 Ltr) | 220 | Sahi |
| Beverages | Soft Drink | Jumbo | 320 | Soft Drink (Jumbo) | 320 | Sahi |
| Beverages | Soft Drink | 500 ml(Sting) | 150 | Soft Drink Sting (500 ml) | 150 | Sahi |
| Beverages | Mineral Water | small | 80 | Mineral Water (Small) | 80 | Sahi |
| Beverages | Mineral Water | Large | 160 | Mineral Water (Large) | 160 | Sahi |
| Beverages | Soft Drink | Can | 160 | Soft Drink Sting (Can) | 160 | Sahi |
| Beverages | Soft Drink | 300 ML | 100 | Soft Drink (300 ml) | 100 | Sahi |
| Beverages | Soft Drink | 345 ML | 120 | Soft Drink (345 ml) | 120 | Sahi |
| Beverages | Sting Can |  | 180 | Soft Drink Sting (Can) | 160 | Qeemat alag |
| Raita & Salad | Raita |  | 100 | Raita | 100 | Sahi |
| Raita & Salad | Fresh Salad |  | 200 | Fresh Salad | 200 | Sahi |
| Extras | Singaporean Sauce |  | 100 | Singaporean Sauce | 100 | Sahi |
| Extras | Butter |  | 200 | Butter | 200 | Sahi |
| Extras | Garlic Fried |  | 100 | Garlic Fried | 100 | Sahi |
| Extras | Cheese |  | 100 | Cheese | 100 | Sahi |
| Extras | Bun |  | 80 | Bun | 80 | Sahi |
| Extras | Dinner Roll |  | 80 | Dinner Roll | 80 | Sahi |
| Extras | Coleslaw |  | 80 | Coleslaw | 80 | Sahi |
| Extras | Mayo Sauce |  | 80 | Mayo Sauce | 80 | Sahi |
| Extras | Paratha | small | 80 | Parhata Small | 80 | Sahi |
| Extras | Paratha | Large | 150 | Parhata Large | 150 | Sahi |
| Midnight Deals | Midnight Deal 01 | Regular | 600 | 2 Pcs Crispy Fried Chicken (Midnight) (deal) | 600 | Sahi |
| Midnight Deals | Midnight Deal 01 | Combo | 800 | 2 Pcs Crispy Fried Chicken + Fries + Drink (Midnight) (deal) | 800 | Sahi |
| Midnight Deals | Midnight Deal 02 | Regular | 650 | 2 Pcs Crispy Fried Chicken (Spicy) (Midnight) (deal) | 650 | Sahi |
| Midnight Deals | Midnight Deal 02 | Combo | 850 | 2 Pcs Crispy Fried Chicken (Spicy) + Fries + Drink (Midnight) (deal) | 850 | Sahi |
| Midnight Deals | Midnight Deal 03 | Regular | 700 | Chicken Crunch Burger + Crispy Fried Chicken (Drum Stick) (Midnight) (deal) | 700 | Sahi |
| Midnight Deals | Midnight Deal 03 | Combo | 900 | Chicken Crunch Burger + Crispy Fried Chicken (Drum Stick) + Fries + Drink (Midnight) (deal) | 900 | Sahi |
| Midnight Deals | Midnight Deal 04 | Regular | 900 | Chicken Zinger Burger + Crispy Fried Chicken (Drum Stick) (Midnight) (deal) | 900 | Sahi |
| Midnight Deals | Midnight Deal 04 | Combo | 1,050 | Chicken Zinger Burger + Crispy Fried Chicken (Drum Stick) + Fries + Drink (Midnight) (deal) | 1,050 | Sahi |
| Midnight Deals | Midnight Deal 05 | Regular | 450 | Chicken Crunch Burger (Midnight) (deal) | 450 | Sahi |
| Midnight Deals | Midnight Deal 05 | Combo | 650 | Chicken Crunch Burger + Fries + Drink (Midnight) (deal) | 650 | Sahi |
| Midnight Deals | Midnight Deal 06 | Regular | 600 | Chicken Zinger Burger (Midnight) (deal) | 600 | Sahi |
| Midnight Deals | Midnight Deal 06 | Combo | 800 | Chicken Zinger Burger + Fries + Drink (Midnight) (deal) | 800 | Sahi |
| Midnight Deals | Midnight Deal 07 | Regular | 650 | Thrill Zinger Burger (Spicy) (Midnight) (deal) | 650 | Sahi |
| Midnight Deals | Midnight Deal 07 | Combo | 850 | Thrill Zinger Burger (Spicy) + Fries + Drink (Midnight) (deal) | 850 | Sahi |
| Midnight Deals | Midnight Deal 08 | Regular | 600 | Beef Burger (Midnight) (deal) | 600 | Sahi |
| Midnight Deals | Midnight Deal 08 | Combo | 800 | Beef Burger + Fries + Drink (Midnight) (deal) | 800 | Sahi |
| Midnight Deals | Midnight Deal 09 | Regular | 700 | Chicken Club Sandwich (Midnight) (deal) | 700 | Sahi |
| Midnight Deals | Midnight Deal 09 | Combo | 900 | Chicken Club Sandwich + Fries + Drink (Midnight) (deal) | 900 | Sahi |
| Midnight Deals | Midnight Deal 10 | Regular | 750 | Bar-B-Que Sandwich (Midnight) (deal) | 750 | Sahi |
| Midnight Deals | Midnight Deal 10 | Combo | 950 | Bar-B-Que Sandwich + Fries + Drink (Midnight) (deal) | 950 | Sahi |
| Midnight Deals | Midnight Deal 11 |  | 950 | Tikka Pizza Fries + 2 Drinks (Midnight) (deal) | 950 | Sahi |
| Midnight Deals | Midnight Deal 12 |  | 550 | Singaporean Rice (Regular) (Midnight) (deal) | 550 | Sahi |
| Midnight Deals | Midnight Deal 13 |  | 2,900 | Singaporean Rice Khaas + 2 Drinks (Midnight) (deal) | 2,900 | Sahi |
| Midnight Deals | Midnight Deal 14 |  | 650 | Chicken Malai Tikka (Chest) (Midnight) (deal) | 650 | Sahi |
| Midnight Deals | Midnight Deal 15 |  | 1,150 | Chicken Malai Boti + Drink (Midnight) (deal) | 1,150 | Sahi |
| Midnight Deals | Midnight Deal 16 |  | 1,250 | Chicken Shahi Chattakh + Drink (Midnight) (deal) | 1,250 | Sahi |
| Midnight Deals | Midnight Deal 17 |  | 1,550 | - | - | POS me nahi mila |
| Midnight Deals | Midnight Deal 18 |  | 1,250 | Chicken Balochi Boti + Drink (Midnight) (deal) | 1,250 | Sahi |
| Midnight Deals | Midnight Deal 19 | Chicken Resham Kebab | 1,150 | Chicken Resham Kebab + Drink (Midnight) (deal) | 1,150 | Sahi |
| Midnight Deals | Midnight Deal 19 | Beef Seekh Kebab | 1,350 | Beef Seekh Kebab + Drink (Midnight) (deal) | 1,350 | Sahi |
| Midnight Deals | Midnight Deal 20 |  | 1,350 | Manchurian With Fried Rice + Drink (Midnight) (deal) | 1,350 | Sahi |
| Midnight Deals | Midnight Deal 21 |  | 1,200 | Chicken Chow Mein + Drink (Midnight) (deal) | 1,200 | Sahi |

## POS me hain, website par nahi (42)

Active, bikne wale, POS par dikhne wale; raw/packaging nahi.

| POS category | Item | Qism | Rs |
| --- | --- | --- | --- |
| Beverages | Cold Drink (Can) | product | 160 |
| Beverages | Soft Drink Sting (345 ml) | product | 130 |
| Burgers | Beef Burger (With Fries) | product | 800 |
| Burgers | Chicken Burger (With Fries) | product | 750 |
| Burgers | Chicken Crunch Burger (With Fries) | product | 650 |
| Burgers | Chicken Zinger Burger (With Fries) | product | 800 |
| Burgers | Thrill Zinger Burger (Spicy, With Fries) | product | 850 |
| Chicken Biryani | Extra Chicken Pcs | product | 200 |
| Crispy Fried Chicken | 2 Pcs Crispy Fried Chicken (Garlic, With Fries) | product | 850 |
| Crispy Fried Chicken | 2 Pcs Crispy Fried Chicken (Spicy, With Fries) | product | 850 |
| Crispy Fried Chicken | 2 Pcs Crispy Fried Chicken (With Fries) | product | 800 |
| Crispy Fried Chicken | 3 Pcs Crispy Fried Chicken (Spicy, With Fries) | product | 1,100 |
| Crispy Fried Chicken | 3 Pcs Crispy Fried Chicken (With Fries) | product | 1,050 |
| Crispy Fried Chicken | Crispy Fried Chicken (Drum Stick) | product | 450 |
| Deals > Chinese Deals | Garlic Rice With Shashlik Bar-B-Que | deal | 1,650 |
| Deals > Exclusive Deals | Exclusive Deal 1 - 2 Zinger + 2 Singaporean Rice + 4 Drinks | deal | 2,500 |
| Deals > Midnight | Miami Supreme Pizza Fries + 2 Drinks (Midnight) | deal | 950 |
| Deals > Pocket Friendly | Chicken Crunch Burger + Crispy Fried Chicken (Drum Stick) + Fries | deal | 900 |
| Deals > Pocket Friendly | Chicken Zinger Burger + Crispy Fried Chicken (Drum Stick) + Fries | deal | 1,100 |
| Deals > Singaporean Platter | Singaporean Rice Khass (2-3 Persons) | deal | 2,900 |
| Dessert | Cream Cocktail Cup | product | 200 |
| Dessert | Cream Cocktail Half Pack | product | 600 |
| Extras | Plain Rice | product | 170 |
| Extras | Platter Rice | product | 350 |
| Extras | Sizzling Charge | product | 200 |
| Extras > BBQ Sauce | BBQ Sauce | product | 80 |
| Extras > BBQ Sauce | Extra Skewer | product | 625 |
| Extras > Fastfood Sauce | Extra Sauce | product | 100 |
| Kids House | Crispy Fried Chicken 1 Pcs (With Fries) | product | 600 |
| Kids House | Hot Shot 9 Pcs (With Fries) | product | 800 |
| Kids House | Nuggets 6 Pcs (With Fries) | product | 850 |
| Paratha | Pori Paratha | product | 80 |
| Pizza Fries | Miami Supreme (Large) | product | 1,300 |
| Pizza Fries | Miami Supreme (Small) | product | 800 |
| Pizza Fries | Pepperoni Pizza Fries (Large) | product | 1,300 |
| Pizza Fries | Pepperoni Pizza Fries (Small) | product | 800 |
| Pizza Fries | Supreme Pizza Fries (Large) | product | 1,300 |
| Pizza Fries | Supreme Pizza Fries (Small) | product | 800 |
| Sandwiches | Chicken Bar-B-Que Sandwich (With Fries) | product | 950 |
| Sandwiches | Chicken Malai Boti Sandwich (With Fries) | product | 950 |
| Sandwiches | Chicken Sandwich (With Fries) | product | 850 |
| Sandwiches | Club Sandwich (With Fries) | product | 900 |
