"""Al Mustafa Rice Mills ERP gate agent.

The only component that touches gate hardware (weighbridge indicator, camera; ANPR, boom
barrier and display board in phase 6). Works offline with an encrypted local queue and talks
to the server through the same sync API as the PWA and the Flutter app, with signed requests.
"""

__version__ = "0.3.0"
