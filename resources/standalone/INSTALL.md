# Installing Budget Pro on your own server

This guide takes you from the ZIP you downloaded to a working shop at your own address, for example
`https://shop.yourbusiness.com`. It is written for cPanel hosting, the kind most hosting companies sell.
You do not need a developer. Set aside about 30 minutes.

You need two files from your buyer page:

- `budget-pro-standalone-<version>.zip`, the program;
- `licence.json`, your licence. It names the address (or addresses) you bought it for.

---

## 1. What your hosting needs

| Need | Where to check in cPanel |
|---|---|
| **PHP 8.2 or newer** (8.3 recommended) | *Select PHP Version* or *MultiPHP Manager* |
| PHP extensions: `bcmath ctype curl fileinfo gd intl mbstring openssl pdo_mysql sodium tokenizer xml zip` | *Select PHP Version › Extensions* |
| **MySQL 5.7+ or MariaDB 10.3+**, with InnoDB (almost every host has it) | *MySQL Databases* |
| About **600 MB** of free disk for the program, plus room for photos and backups | the *Disk usage* panel |
| **Cron jobs** | *Cron Jobs* |
| An **SSL certificate** for your address (free with AutoSSL / Let's Encrypt) | *SSL/TLS Status* |

The installer checks all of this for you and says exactly what is missing.

## 2. Create the address

Decide on the address the shop will live at.

- A **subdomain** such as `shop.yourbusiness.com`: *Domains › Create a new domain* (or *Subdomains*).
  cPanel gives it a web folder such as `public_html/shop.yourbusiness.com`. Note that folder.
- Your **main domain**: its web folder is `public_html`.

Then open *SSL/TLS Status* and make sure the address has a valid certificate (run *AutoSSL* if not).
The installer refuses to run over plain `http://`, because passwords would travel unprotected.

The address must be one your licence lists. If you need another one, ask us from your buyer page.

## 3. Create an empty database

In *MySQL Databases*:

1. Create a database, for example `youracct_shop`.
2. Create a user with a strong password, for example `youracct_shopuser`. Write the password down.
3. *Add user to database*, and tick **ALL PRIVILEGES**.

The database must be **empty**. Use a new one; never one another program uses.

## 4. Upload and extract

1. Open *File Manager* and go to your **home folder**, the one that *contains* `public_html`
   (not `public_html` itself).
2. *Upload* the ZIP there, then right-click it and choose *Extract*.

You now have, side by side in your home folder:

```
budget_pro/          the program (never public)
budget_pro_web/      the program's screens (never public)
web_root_files/      what goes in your address's web folder
PACKAGE.json         the signed list of every file, checked by the installer
INSTALL.md           this guide
```

3. Open `web_root_files`, *Select all* (File Manager's *Settings › Show hidden files* must be ticked,
   so that `.htaccess` is included), and **Move** everything into your address's web folder from step 2,
   for example `public_html/shop.yourbusiness.com`.
4. Leave `budget_pro`, `budget_pro_web` and `PACKAGE.json` where they are. The web folder finds them
   up to four folders above itself, so a web folder inside `public_html` works too.

Do not rename `budget_pro` or `budget_pro_web`.

## 5. Run the installer

Open `https://shop.yourbusiness.com/install.php` (your own address) in your browser. The site sends
you there by itself while the program is not set up yet.

1. **Server checks** show at the top. Fix anything marked in red first (usually a PHP extension).
2. **Licence and address:** choose your `licence.json` and confirm the address, exactly as it shows in
   your browser.
3. **Database:** the host is usually `localhost`, the port `3306`, then the name, user and password
   from step 3. cPanel puts your account name in front of both (`youracct_…`).
4. **Your business:** its name, what kind of business it is, and the country. The country sets your
   currency, time zone and VAT; you can change them later.
5. **The owner:** your name, email, phone number and a password of at least 10 characters. This is the
   account that controls everything, so keep the password safe.
6. **Email (recommended):** see section 7. You can leave it empty and add it later.

Press **Install Budget Pro**. The installer first checks every file of the package against its
signature, then prepares the database in short steps with a progress bar. **Keep the tab open** until
it says your shop is ready (a few minutes). If the tab closes, open `install.php` again in the same
browser and it carries on.

## 6. Add the scheduler (one cron job): required

Reminders, receipts by message, overnight reports and backups run from a scheduler that must be called
**every minute**. The last page of the installer shows your exact line. In cPanel *Cron Jobs*:

- Common settings: **Once Per Minute (`* * * * *`)**.
- Command (with your own home folder, as the installer shows it):

```
cd /home/youracct/budget_pro && /usr/local/bin/php artisan schedule:run >/dev/null 2>&1
```

`/usr/local/bin/php` is the command-line PHP on most cPanel hosts; your host's help pages name it if
yours differs. Do not use "every 5 minutes" or any other interval: jobs due at other minutes would be
skipped without warning.

## 7. Email

Email carries password resets, sign-in codes and staff invitations. Without it, "forgot password"
cannot reach anyone.

In cPanel *Email Accounts*, create an address such as `shop@yourbusiness.com`, then use:

| Setting | Value |
|---|---|
| SMTP host | your **server's own name**, shown in cPanel under *Server Information* (for example `server123.hostingcompany.com`), not `mail.yourbusiness.com`, whose certificate often does not match |
| Port | **465** |
| User | the full email address |
| Password | that mailbox's password |
| From address | the same email address |

Emails are signed with your business's name. The installer sends a test message to the owner at the end.

## 8. Sign in

Open your address and sign in with the owner's email and password. A short setup wizard helps you add
your products (from ready-made lists for your kind of business), money accounts, staff and your first sale.

The previous ("classic") screens are at `https://shop.yourbusiness.com/classic`; the main screens link
to them where needed (budgets and pledges, poultry, device tracking).

## 9. The phone app

The Budget Pro phone app works with your server. In the app, choose *Use my own server* and enter your
address (`https://shop.yourbusiness.com`), or scan the QR code shown in *Settings › Licence & updates*.
Staff sign in with the accounts you create for them under *Team*.

## 10. Backups: keep a copy somewhere else

The scheduler makes a backup every night at 02:15 and keeps the last seven. Each one holds the
database, both settings files, the licence and every photo and logo. Download one regularly from
*Settings › Licence & updates* and keep it **off the server** (a laptop, a flash disk, cloud storage):
backups that live only on the server are lost with it.

**Keep `APP_KEY` safe.** It is in `budget_pro/.env` (and the same value in `budget_pro_web/.env`). Some
settings, such as fiscal (EFRIS) credentials, are stored encrypted with it and cannot be read without it.
Every backup includes both files.

To restore, follow `RESTORE.txt` inside the backup: import `database.sql.gz` with phpMyAdmin (or `mysql`),
put the two `.env` files back, and copy the photos into the web folder's `storage` folder.

## 11. Updates

Your licence includes updates until the date it names; after that the shop keeps working for ever, only
newer versions refuse to install. In *Settings › Licence & updates*:

- **Check now** looks for a newer version.
- **Back up and update** makes a backup first, installs the update during the next scheduler minute,
  checks the shop, and puts everything back automatically if anything fails.
- **Upload and update** does the same with a ZIP you downloaded, for a server without internet access.

Your data, photos and settings are never touched by an update.

## When something goes wrong

| You see | Do this |
|---|---|
| "Budget Pro application folders were not found" | `budget_pro` and `budget_pro_web` must be in your home folder (or up to four folders above the web folder), with those exact names |
| "A release file is missing or was changed" | Upload the ZIP again and extract it whole; some files did not upload |
| "The licence does not cover this address" | Use the exact address from your licence, or ask us to change it from your buyer page |
| "This database already has tables" | Create a new, empty database (section 3) |
| A blank page or "500" after installing | Check the PHP version (8.2+) for this address in *MultiPHP Manager* |
| "Installation is already in progress in another browser" | Continue in the browser you started with |

Still stuck? Contact us from your buyer page and include what the page says.
