USE agrireach;

-- Additive: Philippine commodity catalog and units of measurement for the MySQL rollback copy. Generated from the
-- same catalog list as database/pgsql/20261024_0007_commodity_catalog_and_units.sql (see that file for details).
-- The 30 commodities from the production-records migration keep their IDs; production records keep their values.
-- New tables use the existing tables' charset/collation, which their foreign keys require.
CREATE TABLE IF NOT EXISTS measurement_units (
    code VARCHAR(8) NOT NULL PRIMARY KEY,
    name VARCHAR(40) NOT NULL,
    symbol VARCHAR(10) NOT NULL,
    measure VARCHAR(6) NOT NULL,
    kg_per_unit DECIMAL(10,3) NULL,
    decimals SMALLINT NOT NULL,
    sort_order SMALLINT NOT NULL,
    CONSTRAINT chk_measurement_units_measure CHECK (measure IN ('weight', 'count', 'volume')),
    CONSTRAINT chk_measurement_units_conversion CHECK ((measure = 'weight') = (kg_per_unit IS NOT NULL)),
    CONSTRAINT chk_measurement_units_decimals CHECK (decimals BETWEEN 0 AND 3)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO measurement_units (code, name, symbol, measure, kg_per_unit, decimals, sort_order) VALUES
    ('kg', 'Kilograms', 'kg', 'weight', 1, 2, 1),
    ('mt', 'Metric tons', 'MT', 'weight', 1000, 3, 2),
    ('head', 'Head', 'head', 'count', NULL, 0, 3),
    ('piece', 'Pieces', 'pcs', 'count', NULL, 0, 4),
    ('tray', 'Trays', 'trays', 'count', NULL, 0, 5),
    ('liter', 'Liters', 'L', 'volume', NULL, 2, 6)
ON DUPLICATE KEY UPDATE name = VALUES(name), symbol = VALUES(symbol), measure = VALUES(measure), kg_per_unit = VALUES(kg_per_unit), decimals = VALUES(decimals), sort_order = VALUES(sort_order);

ALTER TABLE commodities
    ADD COLUMN IF NOT EXISTS commodity_group VARCHAR(60) NULL AFTER agricultural_category,
    ADD COLUMN IF NOT EXISTS product_type VARCHAR(16) NOT NULL DEFAULT 'crop' AFTER commodity_group,
    ADD COLUMN IF NOT EXISTS default_unit_code VARCHAR(8) NOT NULL DEFAULT 'kg' AFTER product_type,
    ADD COLUMN IF NOT EXISTS search_names VARCHAR(255) NOT NULL DEFAULT '' AFTER default_unit_code;

UPDATE commodities SET name = 'Palay (Rice)' WHERE agricultural_category = 'Crops' AND name = 'Rice';
UPDATE commodities SET name = 'Cassava (Kamoteng Kahoy)' WHERE agricultural_category = 'Crops' AND name = 'Cassava';
UPDATE commodities SET name = 'Sweet Potato (Kamote)' WHERE agricultural_category = 'Crops' AND name = 'Sweet Potato';
UPDATE commodities SET name = 'Taro (Gabi)' WHERE agricultural_category = 'Crops' AND name = 'Taro';
UPDATE commodities SET name = 'Peanut (Mani)' WHERE agricultural_category = 'Crops' AND name = 'Peanut';
UPDATE commodities SET name = 'Eggplant (Talong)' WHERE agricultural_category = 'Crops' AND name = 'Eggplant';
UPDATE commodities SET name = 'Tomato (Kamatis)' WHERE agricultural_category = 'Crops' AND name = 'Tomato';
UPDATE commodities SET name = 'String Beans (Sitaw)' WHERE agricultural_category = 'Crops' AND name = 'String Beans';
UPDATE commodities SET name = 'Squash (Kalabasa)' WHERE agricultural_category = 'Crops' AND name = 'Squash';
UPDATE commodities SET name = 'Pig / Hog (Fattener)' WHERE agricultural_category = 'Livestock' AND name = 'Pig';
UPDATE commodities SET name = 'Milkfish (Bangus)' WHERE agricultural_category = 'Fishpond' AND name = 'Bangus (Milkfish)';
UPDATE commodities SET name = 'Mud Crab (Alimango)' WHERE agricultural_category = 'Fishpond' AND name = 'Mud Crab';

INSERT INTO commodities (name, agricultural_category, commodity_group, product_type, default_unit_code, search_names, status) VALUES
    ('Palay (Rice)', 'Crops', 'Rice and Cereals', 'crop', 'kg', 'rice, palay, bigas, humay, inbred, hybrid', 'Active'),
    ('Corn', 'Crops', 'Rice and Cereals', 'crop', 'kg', 'mais, maize', 'Inactive'),
    ('White Corn', 'Crops', 'Rice and Cereals', 'crop', 'kg', 'mais, puting mais, white maize', 'Active'),
    ('Yellow Corn', 'Crops', 'Rice and Cereals', 'crop', 'kg', 'mais, dilaw na mais, yellow maize, feed corn', 'Active'),
    ('Sweet Corn', 'Crops', 'Rice and Cereals', 'crop', 'kg', 'mais, sweet maize', 'Active'),
    ('Sorghum', 'Crops', 'Rice and Cereals', 'crop', 'kg', 'sorghum, batad', 'Active'),
    ('Adlai', 'Crops', 'Rice and Cereals', 'crop', 'kg', 'adlai, adlay, jobs tears', 'Active'),
    ('Cassava (Kamoteng Kahoy)', 'Crops', 'Root Crops and Tubers', 'crop', 'kg', 'cassava, kamoteng kahoy, balanghoy, manioc', 'Active'),
    ('Sweet Potato (Kamote)', 'Crops', 'Root Crops and Tubers', 'crop', 'kg', 'sweet potato, kamote, camote', 'Active'),
    ('Taro (Gabi)', 'Crops', 'Root Crops and Tubers', 'crop', 'kg', 'taro, gabi', 'Active'),
    ('Ube (Purple Yam)', 'Crops', 'Root Crops and Tubers', 'crop', 'kg', 'ube, ubi, purple yam', 'Active'),
    ('Potato', 'Crops', 'Root Crops and Tubers', 'crop', 'kg', 'potato, patatas', 'Active'),
    ('Yam (Tugi)', 'Crops', 'Root Crops and Tubers', 'crop', 'kg', 'yam, tugi, lesser yam', 'Active'),
    ('Arrowroot (Uraro)', 'Crops', 'Root Crops and Tubers', 'crop', 'kg', 'arrowroot, uraro', 'Active'),
    ('Eggplant (Talong)', 'Crops', 'Vegetables', 'crop', 'kg', 'eggplant, talong, aubergine', 'Active'),
    ('Tomato (Kamatis)', 'Crops', 'Vegetables', 'crop', 'kg', 'tomato, kamatis', 'Active'),
    ('Bitter Gourd (Ampalaya)', 'Crops', 'Vegetables', 'crop', 'kg', 'bitter gourd, ampalaya, amargoso, bitter melon', 'Active'),
    ('Okra', 'Crops', 'Vegetables', 'crop', 'kg', 'okra, lady finger', 'Active'),
    ('Squash (Kalabasa)', 'Crops', 'Vegetables', 'crop', 'kg', 'squash, kalabasa, pumpkin', 'Active'),
    ('String Beans (Sitaw)', 'Crops', 'Vegetables', 'crop', 'kg', 'string beans, sitaw, batong, yardlong bean', 'Active'),
    ('Snap Beans (Baguio Beans)', 'Crops', 'Vegetables', 'crop', 'kg', 'snap beans, baguio beans, habichuelas, abitsuelas, green beans', 'Active'),
    ('Lima Beans (Patani)', 'Crops', 'Vegetables', 'crop', 'kg', 'lima beans, patani', 'Active'),
    ('Winged Beans (Sigarilyas)', 'Crops', 'Vegetables', 'crop', 'kg', 'winged beans, sigarilyas, kalamismis', 'Active'),
    ('Mung Beans (Monggo)', 'Crops', 'Vegetables', 'crop', 'kg', 'mung beans, mungbean, monggo, munggo, balatong', 'Active'),
    ('Peanut (Mani)', 'Crops', 'Vegetables', 'crop', 'kg', 'peanut, mani, groundnut', 'Active'),
    ('Pechay', 'Crops', 'Vegetables', 'crop', 'kg', 'pechay, petsay, pak choi, bok choy', 'Active'),
    ('Chinese Cabbage (Petsay Baguio)', 'Crops', 'Vegetables', 'crop', 'kg', 'chinese cabbage, petsay baguio, wombok, napa cabbage', 'Active'),
    ('Cabbage', 'Crops', 'Vegetables', 'crop', 'kg', 'cabbage, repolyo', 'Active'),
    ('Carrots', 'Crops', 'Vegetables', 'crop', 'kg', 'carrots, karot', 'Active'),
    ('Cauliflower', 'Crops', 'Vegetables', 'crop', 'kg', 'cauliflower, koliplor', 'Active'),
    ('Broccoli', 'Crops', 'Vegetables', 'crop', 'kg', 'broccoli, brokoli', 'Active'),
    ('Lettuce', 'Crops', 'Vegetables', 'crop', 'kg', 'lettuce, litsugas', 'Active'),
    ('Celery', 'Crops', 'Vegetables', 'crop', 'kg', 'celery, kintsay', 'Active'),
    ('Kangkong (Water Spinach)', 'Crops', 'Vegetables', 'crop', 'kg', 'kangkong, tangkong, water spinach', 'Active'),
    ('Mustard Greens (Mustasa)', 'Crops', 'Vegetables', 'crop', 'kg', 'mustard greens, mustasa', 'Active'),
    ('Malunggay (Moringa)', 'Crops', 'Vegetables', 'crop', 'kg', 'malunggay, kamunggay, moringa', 'Active'),
    ('Saluyot (Jute Mallow)', 'Crops', 'Vegetables', 'crop', 'kg', 'saluyot, jute mallow', 'Active'),
    ('Alugbati (Malabar Spinach)', 'Crops', 'Vegetables', 'crop', 'kg', 'alugbati, malabar spinach', 'Active'),
    ('Cucumber (Pipino)', 'Crops', 'Vegetables', 'crop', 'kg', 'cucumber, pipino', 'Active'),
    ('Chayote (Sayote)', 'Crops', 'Vegetables', 'crop', 'kg', 'chayote, sayote', 'Active'),
    ('Bottle Gourd (Upo)', 'Crops', 'Vegetables', 'crop', 'kg', 'bottle gourd, upo, tabayag', 'Active'),
    ('Sponge Gourd (Patola)', 'Crops', 'Vegetables', 'crop', 'kg', 'sponge gourd, patola, luffa', 'Active'),
    ('Bell Pepper', 'Crops', 'Vegetables', 'crop', 'kg', 'bell pepper, sweet pepper, capsicum, sili', 'Active'),
    ('Chili Pepper (Siling Labuyo)', 'Crops', 'Vegetables', 'crop', 'kg', 'chili pepper, siling labuyo, sili, bird eye chili', 'Active'),
    ('Finger Chili (Siling Haba)', 'Crops', 'Vegetables', 'crop', 'kg', 'finger chili, siling haba, siling pansigang, sili, long chili', 'Active'),
    ('Ginger (Luya)', 'Crops', 'Vegetables', 'crop', 'kg', 'ginger, luya', 'Active'),
    ('Garlic (Bawang)', 'Crops', 'Vegetables', 'crop', 'kg', 'garlic, bawang, ahos', 'Active'),
    ('Onion (Sibuyas)', 'Crops', 'Vegetables', 'crop', 'kg', 'onion, sibuyas, red onion, white onion, bulb onion', 'Active'),
    ('Spring Onion', 'Crops', 'Vegetables', 'crop', 'kg', 'spring onion, green onion, scallion, dahon ng sibuyas, sibuyas dahonan', 'Active'),
    ('Radish (Labanos)', 'Crops', 'Vegetables', 'crop', 'kg', 'radish, labanos', 'Active'),
    ('Banana', 'Crops', 'Fruits', 'crop', 'kg', 'banana, saging', 'Inactive'),
    ('Banana (Saba)', 'Crops', 'Fruits', 'crop', 'kg', 'banana, saging, saba, cardaba, cooking banana', 'Active'),
    ('Banana (Lakatan)', 'Crops', 'Fruits', 'crop', 'kg', 'banana, saging, lakatan', 'Active'),
    ('Banana (Latundan)', 'Crops', 'Fruits', 'crop', 'kg', 'banana, saging, latundan', 'Active'),
    ('Cavendish Banana', 'Crops', 'Fruits', 'crop', 'kg', 'banana, saging, cavendish, export banana', 'Active'),
    ('Mango', 'Crops', 'Fruits', 'crop', 'kg', 'mango, mangga', 'Inactive'),
    ('Mango (Carabao)', 'Crops', 'Fruits', 'crop', 'kg', 'mango, mangga, carabao mango, manila mango', 'Active'),
    ('Mango (Pico)', 'Crops', 'Fruits', 'crop', 'kg', 'mango, mangga, pico', 'Active'),
    ('Indian Mango', 'Crops', 'Fruits', 'crop', 'kg', 'mango, mangga, indian mango', 'Active'),
    ('Pineapple', 'Crops', 'Fruits', 'crop', 'kg', 'pineapple, pinya', 'Active'),
    ('Papaya', 'Crops', 'Fruits', 'crop', 'kg', 'papaya, kapayas', 'Active'),
    ('Calamansi', 'Crops', 'Fruits', 'crop', 'kg', 'calamansi, kalamansi, calamondin, limonsito', 'Active'),
    ('Coconut', 'Crops', 'Fruits', 'crop', 'piece', 'coconut, niyog, lubi, buko', 'Active'),
    ('Watermelon (Pakwan)', 'Crops', 'Fruits', 'crop', 'kg', 'watermelon, pakwan', 'Active'),
    ('Melon', 'Crops', 'Fruits', 'crop', 'kg', 'melon, cantaloupe, honeydew', 'Active'),
    ('Guava (Bayabas)', 'Crops', 'Fruits', 'crop', 'kg', 'guava, bayabas', 'Active'),
    ('Jackfruit (Langka)', 'Crops', 'Fruits', 'crop', 'kg', 'jackfruit, langka, nangka', 'Active'),
    ('Lanzones', 'Crops', 'Fruits', 'crop', 'kg', 'lanzones, lansones', 'Active'),
    ('Rambutan', 'Crops', 'Fruits', 'crop', 'kg', 'rambutan', 'Active'),
    ('Durian', 'Crops', 'Fruits', 'crop', 'kg', 'durian', 'Active'),
    ('Mangosteen', 'Crops', 'Fruits', 'crop', 'kg', 'mangosteen', 'Active'),
    ('Marang', 'Crops', 'Fruits', 'crop', 'kg', 'marang', 'Active'),
    ('Santol', 'Crops', 'Fruits', 'crop', 'kg', 'santol, cotton fruit', 'Active'),
    ('Chico (Sapodilla)', 'Crops', 'Fruits', 'crop', 'kg', 'chico, tsiko, sapodilla', 'Active'),
    ('Star Apple (Caimito)', 'Crops', 'Fruits', 'crop', 'kg', 'star apple, caimito, kaimito', 'Active'),
    ('Atis (Sugar Apple)', 'Crops', 'Fruits', 'crop', 'kg', 'atis, sugar apple, custard apple', 'Active'),
    ('Pomelo (Suha)', 'Crops', 'Fruits', 'crop', 'kg', 'pomelo, suha', 'Active'),
    ('Dalandan', 'Crops', 'Fruits', 'crop', 'kg', 'dalandan, philippine orange', 'Active'),
    ('Mandarin (Ponkan)', 'Crops', 'Fruits', 'crop', 'kg', 'mandarin, ponkan', 'Active'),
    ('Avocado', 'Crops', 'Fruits', 'crop', 'kg', 'avocado, abokado', 'Active'),
    ('Guyabano (Soursop)', 'Crops', 'Fruits', 'crop', 'kg', 'guyabano, soursop', 'Active'),
    ('Dragon Fruit', 'Crops', 'Fruits', 'crop', 'kg', 'dragon fruit, pitaya', 'Active'),
    ('Sugarcane', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'sugarcane, tubo', 'Active'),
    ('Cacao', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'cacao, kakaw, cocoa, cacao fruit, cacao pods', 'Active'),
    ('Coffee', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'coffee, kape', 'Inactive'),
    ('Coffee (Robusta)', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'coffee, kape, robusta', 'Active'),
    ('Coffee (Arabica)', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'coffee, kape, arabica', 'Active'),
    ('Coffee (Liberica)', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'coffee, kape, liberica, barako, kapeng barako', 'Active'),
    ('Coffee (Excelsa)', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'coffee, kape, excelsa', 'Active'),
    ('Rubber', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'rubber, goma, cup lump, latex', 'Active'),
    ('Abaca', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'abaca, abaka, manila hemp', 'Active'),
    ('Tobacco', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'tobacco, tabako', 'Active'),
    ('Oil Palm', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'oil palm, palm oil, fresh fruit bunch', 'Active'),
    ('Pili Nut', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'pili nut, pili', 'Active'),
    ('Cashew (Kasoy)', 'Crops', 'Commercial / Industrial Crops', 'crop', 'kg', 'cashew, kasoy', 'Active'),
    ('Turmeric (Luyang Dilaw)', 'Crops', 'Herbs, Spices and Other Crops', 'crop', 'kg', 'turmeric, luyang dilaw, dulaw', 'Active'),
    ('Lemongrass (Tanglad)', 'Crops', 'Herbs, Spices and Other Crops', 'crop', 'kg', 'lemongrass, tanglad, salay', 'Active'),
    ('Pandan', 'Crops', 'Herbs, Spices and Other Crops', 'crop', 'kg', 'pandan, screwpine', 'Active'),
    ('Basil (Balanoy)', 'Crops', 'Herbs, Spices and Other Crops', 'crop', 'kg', 'basil, sweet basil, balanoy', 'Active'),
    ('Oregano', 'Crops', 'Herbs, Spices and Other Crops', 'crop', 'kg', 'oregano', 'Active'),
    ('Black Pepper (Paminta)', 'Crops', 'Herbs, Spices and Other Crops', 'crop', 'kg', 'black pepper, paminta, peppercorn', 'Active'),
    ('Pig / Hog (Fattener)', 'Livestock', 'Swine', 'live_animal', 'head', 'pig, hog, swine, baboy, fattener, fattening pig, slaughter hog', 'Active'),
    ('Native Pig', 'Livestock', 'Swine', 'live_animal', 'head', 'pig, swine, baboy, native pig', 'Active'),
    ('Piglets (Weanlings)', 'Livestock', 'Swine', 'live_animal', 'head', 'pig, swine, baboy, biik, piglet, weanling', 'Active'),
    ('Breeder Sow (Sold or Culled)', 'Livestock', 'Swine', 'breeding_stock', 'head', 'pig, swine, baboy, inahin, sow, breeder', 'Active'),
    ('Boar (Sold or Culled)', 'Livestock', 'Swine', 'breeding_stock', 'head', 'pig, swine, baboy, barako, boar, breeder', 'Active'),
    ('Cattle', 'Livestock', 'Cattle', 'live_animal', 'head', 'cattle, baka', 'Inactive'),
    ('Beef Cattle', 'Livestock', 'Cattle', 'live_animal', 'head', 'cattle, baka, beef', 'Active'),
    ('Dairy Cattle', 'Livestock', 'Cattle', 'live_animal', 'head', 'cattle, baka, dairy cow, gatasan', 'Active'),
    ('Carabao', 'Livestock', 'Carabao', 'live_animal', 'head', 'carabao, kalabaw, buffalo', 'Inactive'),
    ('Native Carabao', 'Livestock', 'Carabao', 'live_animal', 'head', 'carabao, kalabaw, swamp buffalo, native carabao', 'Active'),
    ('Dairy Buffalo', 'Livestock', 'Carabao', 'live_animal', 'head', 'carabao, kalabaw, buffalo, murrah, riverine buffalo, dairy carabao', 'Active'),
    ('Goat', 'Livestock', 'Goats', 'live_animal', 'head', 'goat, kambing', 'Inactive'),
    ('Native Goat', 'Livestock', 'Goats', 'live_animal', 'head', 'goat, kambing, native goat', 'Active'),
    ('Meat Goat', 'Livestock', 'Goats', 'live_animal', 'head', 'goat, kambing, boer, chevon, meat goat', 'Active'),
    ('Dairy Goat', 'Livestock', 'Goats', 'live_animal', 'head', 'goat, kambing, anglo-nubian, saanen, dairy goat', 'Active'),
    ('Sheep', 'Livestock', 'Other Livestock', 'live_animal', 'head', 'sheep, tupa', 'Active'),
    ('Rabbit', 'Livestock', 'Other Livestock', 'live_animal', 'head', 'rabbit, kuneho', 'Active'),
    ('Cow''s Milk', 'Livestock', 'Livestock Products', 'animal_product', 'liter', 'milk, gatas, gatas ng baka, cow milk, fresh milk', 'Active'),
    ('Carabao''s Milk', 'Livestock', 'Livestock Products', 'animal_product', 'liter', 'milk, gatas, gatas ng kalabaw, carabao milk, buffalo milk', 'Active'),
    ('Goat''s Milk', 'Livestock', 'Livestock Products', 'animal_product', 'liter', 'milk, gatas, gatas ng kambing, goat milk', 'Active'),
    ('Chicken', 'Poultry', 'Chickens', 'live_animal', 'head', 'chicken, manok', 'Inactive'),
    ('Broiler Chicken', 'Poultry', 'Chickens', 'live_animal', 'head', 'chicken, manok, broiler, meat chicken', 'Active'),
    ('Native Chicken', 'Poultry', 'Chickens', 'live_animal', 'head', 'chicken, manok, native chicken, manok bisaya, manok tagalog', 'Active'),
    ('Layer Chicken', 'Poultry', 'Chickens', 'live_animal', 'head', 'chicken, manok, layer, laying hen, spent hen', 'Active'),
    ('Free-Range Chicken', 'Poultry', 'Chickens', 'live_animal', 'head', 'chicken, manok, free range, range chicken', 'Active'),
    ('Duck', 'Poultry', 'Ducks', 'live_animal', 'head', 'duck, itik, pato', 'Inactive'),
    ('Native Duck (Itik)', 'Poultry', 'Ducks', 'live_animal', 'head', 'duck, itik, pato, native duck, mallard', 'Active'),
    ('Meat Duck (Pato)', 'Poultry', 'Ducks', 'live_animal', 'head', 'duck, pato, muscovy, pekin, meat duck', 'Active'),
    ('Quail (Pugo)', 'Poultry', 'Other Poultry', 'live_animal', 'head', 'quail, pugo', 'Active'),
    ('Turkey (Pabo)', 'Poultry', 'Other Poultry', 'live_animal', 'head', 'turkey, pabo', 'Active'),
    ('Goose (Gansa)', 'Poultry', 'Other Poultry', 'live_animal', 'head', 'goose, gansa', 'Active'),
    ('Chicken Eggs', 'Poultry', 'Poultry Products', 'animal_product', 'piece', 'eggs, itlog, itlog ng manok, table eggs', 'Active'),
    ('Duck Eggs', 'Poultry', 'Poultry Products', 'animal_product', 'piece', 'eggs, itlog, itlog ng itik, balut, penoy, salted egg', 'Active'),
    ('Quail Eggs', 'Poultry', 'Poultry Products', 'animal_product', 'piece', 'eggs, itlog, itlog ng pugo', 'Active'),
    ('Tilapia', 'Fishpond', 'Freshwater Fish', 'aquaculture', 'kg', 'tilapia, red tilapia, saline tilapia, brackish tilapia', 'Active'),
    ('Catfish (Hito)', 'Fishpond', 'Freshwater Fish', 'aquaculture', 'kg', 'catfish, hito', 'Active'),
    ('Pangasius', 'Fishpond', 'Freshwater Fish', 'aquaculture', 'kg', 'pangasius, swai, cream dory', 'Active'),
    ('Common Carp', 'Fishpond', 'Freshwater Fish', 'aquaculture', 'kg', 'carp, carpa, karpa, common carp', 'Active'),
    ('Bighead Carp', 'Fishpond', 'Freshwater Fish', 'aquaculture', 'kg', 'carp, carpa, karpa, bighead carp', 'Active'),
    ('Milkfish (Bangus)', 'Fishpond', 'Brackish-water and Marine Fish', 'aquaculture', 'kg', 'milkfish, bangus, sabalo', 'Active'),
    ('Asian Sea Bass (Apahap)', 'Fishpond', 'Brackish-water and Marine Fish', 'aquaculture', 'kg', 'sea bass, seabass, apahap, barramundi', 'Active'),
    ('Grouper (Lapu-lapu)', 'Fishpond', 'Brackish-water and Marine Fish', 'aquaculture', 'kg', 'grouper, lapu-lapu, lapu lapu, pugapo', 'Active'),
    ('Rabbitfish (Samaral / Danggit)', 'Fishpond', 'Brackish-water and Marine Fish', 'aquaculture', 'kg', 'rabbitfish, siganid, samaral, danggit, kitong', 'Active'),
    ('Shrimp', 'Fishpond', 'Shrimp and Prawns', 'aquaculture', 'kg', 'shrimp, hipon', 'Inactive'),
    ('Whiteleg Shrimp (Vannamei)', 'Fishpond', 'Shrimp and Prawns', 'aquaculture', 'kg', 'shrimp, hipon, vannamei, white shrimp, whiteleg', 'Active'),
    ('Tiger Prawn (Sugpo)', 'Fishpond', 'Shrimp and Prawns', 'aquaculture', 'kg', 'prawn, shrimp, sugpo, lukon, tiger prawn, black tiger', 'Active'),
    ('Giant Freshwater Prawn (Ulang)', 'Fishpond', 'Shrimp and Prawns', 'aquaculture', 'kg', 'prawn, ulang, freshwater prawn, giant freshwater prawn', 'Active'),
    ('Mud Crab (Alimango)', 'Fishpond', 'Crabs', 'aquaculture', 'kg', 'crab, mud crab, alimango', 'Active'),
    ('Oysters (Talaba)', 'Fishpond', 'Mollusks and Shellfish', 'aquaculture', 'kg', 'oyster, talaba, shellfish', 'Active'),
    ('Green Mussels (Tahong)', 'Fishpond', 'Mollusks and Shellfish', 'aquaculture', 'kg', 'mussel, tahong, amahong, shellfish', 'Active'),
    ('Other Cultured Shellfish', 'Fishpond', 'Mollusks and Shellfish', 'aquaculture', 'kg', 'shellfish, clam, kabibe', 'Active'),
    ('Seaweed (Kappaphycus / Eucheuma)', 'Fishpond', 'Seaweeds', 'aquaculture', 'kg', 'seaweed, guso, agar-agar, eucheuma, kappaphycus, cottonii, spinosum, carrageenan', 'Active')
ON DUPLICATE KEY UPDATE commodity_group = VALUES(commodity_group), product_type = VALUES(product_type),
    default_unit_code = VALUES(default_unit_code), search_names = VALUES(search_names), status = VALUES(status);

ALTER TABLE commodities
    MODIFY commodity_group VARCHAR(60) NOT NULL,
    ADD CONSTRAINT chk_commodities_product_type CHECK (product_type IN ('crop', 'live_animal', 'breeding_stock', 'animal_product', 'aquaculture')),
    ADD CONSTRAINT fk_commodities_default_unit FOREIGN KEY (default_unit_code) REFERENCES measurement_units(code) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD INDEX idx_commodities_category_group (agricultural_category, commodity_group);

CREATE TABLE IF NOT EXISTS commodity_units (
    commodity_id INT UNSIGNED NOT NULL,
    unit_code VARCHAR(8) NOT NULL,
    PRIMARY KEY (commodity_id, unit_code),
    INDEX fk_commodity_units_unit (unit_code),
    CONSTRAINT fk_commodity_units_commodity FOREIGN KEY (commodity_id) REFERENCES commodities(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_commodity_units_unit FOREIGN KEY (unit_code) REFERENCES measurement_units(code) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT IGNORE INTO commodity_units (commodity_id, unit_code)
SELECT c.id, u.code FROM commodities c JOIN measurement_units u ON
       (c.product_type IN ('crop', 'aquaculture') AND u.code IN ('kg', 'mt'))
    OR (c.product_type IN ('live_animal', 'breeding_stock') AND u.code IN ('head', 'kg'))
    OR (c.product_type = 'animal_product' AND c.agricultural_category = 'Livestock' AND u.code = 'liter')
    OR (c.product_type = 'animal_product' AND c.agricultural_category = 'Poultry' AND u.code IN ('piece', 'tray'))
    OR (c.agricultural_category = 'Crops' AND c.name = 'Coconut' AND u.code = 'piece');

ALTER TABLE production_records
    ADD COLUMN IF NOT EXISTS quantity DECIMAL(14,3) NULL AFTER quantity_kg,
    ADD COLUMN IF NOT EXISTS unit_code VARCHAR(8) NOT NULL DEFAULT 'kg' AFTER quantity;
UPDATE production_records SET quantity = quantity_kg WHERE quantity IS NULL;
ALTER TABLE production_records
    MODIFY quantity DECIMAL(14,3) NOT NULL,
    MODIFY quantity_kg DECIMAL(12,2) NULL,
    ADD CONSTRAINT chk_production_records_quantity_positive CHECK (quantity > 0),
    ADD CONSTRAINT fk_production_records_unit FOREIGN KEY (unit_code) REFERENCES measurement_units(code) ON DELETE RESTRICT ON UPDATE CASCADE;
