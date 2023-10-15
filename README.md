# DoubleEnderChest

A PMMP plugin that doubles the size of the Ender Chest

[![](https://poggit.pmmp.io/shield.api/DoubleEnderChest)](https://poggit.pmmp.io/p/DoubleEnderChest)
[![](https://poggit.pmmp.io/shield.dl.total/DoubleEnderChest)](https://poggit.pmmp.io/p/DoubleEnderChest)

# Usage

When a player logs in, the plugin fetches the contents of the double inventory from your database. If the database is slow, or if several players are trying to connect at the same time, this operation may be slow. At any time, you can sneak + right-click on an Ender chest to open the regular inventory.

# Configuration

Inside of the `plugin_data/DoubleEnderChest/config.yml` file, you may change the following:

-   **database** - The configuration of the database used to save players ender chests
