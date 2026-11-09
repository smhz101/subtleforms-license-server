type: Fixed
A seat is now kept for a site address with its path, so a copy of a site in a subdirectory (example.com/staging) no longer shares the seat of the site at the root, and deactivating one leaves the other; a subdirectory site that was activated by host only takes over its idle host-only seat the first time it sends its path.
