name: PSC Jobs Cron

on:
  schedule:
    - cron: "0 * * * *"   # Every hour
  workflow_dispatch:

jobs:
  run-psc-cron:
    runs-on: ubuntu-latest

    steps:
      - name: Checkout repository
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"

      - name: Run PSC job cron
        env:
          TELEGRAM_BOT_TOKEN: ${{ secrets.TELEGRAM_BOT_TOKEN }}
          TELEGRAM_CHAT_ID: ${{ secrets.TELEGRAM_CHAT_ID }}
        run: php cron-psc.php
