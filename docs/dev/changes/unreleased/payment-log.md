type: Changed
Failures in payment paths (Paddle lookups, order polling, webhook database writes, account creation) are now written to the WooCommerce log under the source sfls-paddle and, where an order exists, to a private order note.
