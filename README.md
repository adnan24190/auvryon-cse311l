# Auvryon - CSE311L Database Implementation

**Auvryon - Intelligent Digital Finance & Payment Ecosystem** (simulated money only)
NSU CSE311L (Database Systems Lab) - **Group 1, Section 3**, Faculty Tns1

| Member | ID |
|---|---|
| Khandaker Md.Tafsir Hossain | 1921945042 |
| Ashraful Kabir Uchas | 2413707042 |
| Tahsin Rafid | 2422417042 |
| Adnan Shafi | 2422272642 |

## The file to review
**[`database/auvryon_db.sql`](database/auvryon_db.sql)** - schema **and** sample data in one script.

* PART 1: 51 tables with 51 primary keys, 86 foreign keys, 37 UNIQUE, 8 CHECK, 329 NOT NULL columns, 39 ENUM columns (InnoDB, utf8mb4).
* PART 2: 1,008 rows of labelled DEMO data - **every table has at least 3 rows**.
* Matches the approved ERD and Schema Design (`docs/Auvryon_ERD_and_Schema_Design.pdf`): 51/51 tables, 369/369 columns, 86/86 foreign keys.

## Import
```
mysql -u root -p < database/auvryon_db.sql          # drops and re-creates database auvryon_db
```
or MySQL Workbench: *File > Open SQL Script > auvryon_db.sql > Execute*; or phpMyAdmin (XAMPP) *Import*.
Tested on **MySQL 8.0.46**. The schema also loaded on MariaDB 10.11 (XAMPP's engine) earlier; XAMPP users should test the import once before the class.

## Repository layout
| Path | Content |
|---|---|
| `database/auvryon_db.sql` | schema + sample data (submission file) |
| `database/schema.sql`, `database/sample_data.sql` | the same, split into structure and data |
| `database/queries/` | `presentation_queries.sql`, `constraint_tests.sql` (statements MySQL must reject), `row_counts.sql`, `useful_queries.sql` |
| `docs/` | ERD & Schema Design (Chen notation), Database Implementation Report, ERD images |
| `app/` | the PHP/MySQL web application that uses this database (see `app/README.md`) |

## Quick checks after importing
```sql
USE auvryon_db;
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='auvryon_db';   -- 51
SOURCE database/queries/row_counts.sql;                                            -- every table >= 3 rows
```
Demo application login (if you run `app/`): `tahsin@auvryon.test` / `Demo@1234`, PIN `1234`.

> All money, exchange rates, tokens and biometrics in this project are simulated demonstration data.
