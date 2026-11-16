# AushadhBox Pharmacy analytics (Python)

Every night, for each shop: how much of each medicine will sell, what to reorder, which batches may expire unsold
and where they could go instead, and medicines suddenly selling much faster (an early sign of a local fever or
diarrhoea outbreak). The suggestions appear on the shop's dashboard in the portal.

Only the Python standard library is used (3.9+): it runs on the same server as the platform, a ₹300/month VPS or the
shop's own computer.

## What it calculates
| Suggestion | How |
|---|---|
| **Demand forecast** | Croston's method with the Syntetos-Boylan correction for medicines that sell on fewer than half the days (most of a pharmacy's range); exponential smoothing for daily sellers. A season factor by medicine group and month: fever medicines, ORS and antimalarials rise in the monsoon, cough and cold in winter. |
| **Reorder** | Order-up-to: enough for the distributor's lead time (1 day in cities, 2 in towns, 4 in villages) plus a week, with safety stock at a 95% service level, or **99% for essential medicines** (insulin, antiepileptics, TB medicines, thyroid, blood thinners, inhalers). Batches expiring within 30 days don't count as stock. |
| **Expiry risk** | First-expiring batches sell first (FEFO). For each batch, how much will probably still be on the shelf when it expires. |
| **Move before expiry** | Other shops of the same business within 40 km that sell the medicine faster (no wholesale licence needed inside one business). Otherwise: return it to the distributor in time. |
| **Demand jump** | Last 7 days against the 28 before, with a robust score (median and MAD). Fever, ORS and antimalarial jumps are worded as possible outbreaks, and the shop is told to keep extra stock. |
| **Delivery rounds** (`routes.py`) | Order of village pickup points and home deliveries: nearest neighbour, then 2-opt, with a road factor (1.3 in towns, 1.45 in villages) and two-wheeler speeds. |

A shop that joined recently isn't told its whole stock "may expire": expiry warnings for medicines with no sales wait until there are 60 days of history.

## Run
```
cd pharmacy-platform/analytics
python3 -m abs_analytics.run --url https://pharmacy.example.in --token abs_...   # one shop, with its sync token
python3 -m abs_analytics.run --file history.json --dry-run                        # offline: print the suggestions
```
Cron, nightly at 2 am for each shop token (keep tokens in a file only root can read):
```
0 2 * * * cd /var/www/pharmacy-platform/analytics && while read t; do python3 -m abs_analytics.run --url https://pharmacy.example.in --token "$t"; done < /etc/aushadhbox/analytics-tokens
```
It uses `sync.php?action=history` (sales by product and day, stock by batch, other shops of the business) and
`sync.php?action=insights` (replaces the open analytics suggestions). No customer data is read.

## Test
```
python3 -m unittest discover -s tests -t .
```
19 tests: medicine groups and seasons, Croston against a hand-worked value, regular vs intermittent sellers, monsoon
uplift, outbreak detection, reorder and essential safety stock, near-expiry batches not counted, FEFO expiry risk,
expired batches, transfer targets within 40 km, distances (Coimbatore–Chennai), a round that visits every stop
without zig-zag, a full night's run, and a new shop with no history.

## Next steps (roadmap)
- **District signal for health officers:** combine anonymous "demand jump" scores from many shops in a district into an early-warning map for dengue, malaria and diarrhoea, shared with the district surveillance unit (IDSP) under a data-sharing agreement.
- **Learn each shop's own seasons** once it has 12+ months of sales, instead of the default factors.
- **Price and generic suggestions:** show the Jan Aushadhi generic and saving when a branded medicine is often bought.
