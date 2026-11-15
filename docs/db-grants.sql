-- Least-privilege database users for AushadhBox. Run once as the database administrator (cPanel → MySQL Databases does the
-- same with checkboxes). Each app gets its own user, limited to its own database; neither can read the other's data,
-- touch other databases, read files on the server, or create users. Replace the passwords with long random ones.
--
-- MySQL / MariaDB have no row-level security (RLS). Row isolation is enforced in the code instead: every query on a
-- family's data carries "WHERE user_id = <the signed-in household>" (own(), member_of()), and every pharmacy query carries
-- the staff member's branch / business (my_branch(), my_order()). tests/security_test.sh checks this across accounts.

-- 1. Family app (database: aushadhbox)
CREATE USER IF NOT EXISTS 'aushadhbox_app'@'localhost' IDENTIFIED BY 'CHANGE-ME-long-random-1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON aushadhbox.* TO 'aushadhbox_app'@'localhost';
--    (CREATE / ALTER / INDEX / REFERENCES are only for the self-upgrade after a new version is uploaded. For the strictest set-up,
--     give the app SELECT, INSERT, UPDATE, DELETE only and run upgrades with a second "migrate" user from the command line.)

-- 2. Pharmacy network (database: aushadhbox_pharmacy)
CREATE USER IF NOT EXISTS 'pharmacy_app'@'localhost' IDENTIFIED BY 'CHANGE-ME-long-random-2';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON aushadhbox_pharmacy.* TO 'pharmacy_app'@'localhost';

-- 3. Never: GRANT ALL ON *.*, SUPER, FILE, PROCESS or GRANT OPTION for an app user, and never connect the apps as root.
--    Admin → Security in the family app warns if its user has any of these.
FLUSH PRIVILEGES;
