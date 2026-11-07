# Corn / Sesame price-unit correction — 18 October 2026

The saved Soda accepts KG or MAUND (and the older master-based route also accepts MT), but both the non-Rice bill preview and the
server always divided net payable kilograms by the maund weight before applying
the entered rate. In an authenticated disposable bill request, 1,000 kg at an
explicit Rs100/kg became Rs2,500 instead of Rs100,000. The regression fails on
the original code. No original production bill was posted or changed.

Preview and server now use the saved price unit: net kg × rate for KG, net kg
÷ configured maund kg × rate for MAUND, or net kg ÷ 1,000 × rate for MT. Existing KAT deductions happen first.
Legacy records with no rateUnit retain the previous MAUND interpretation;
explicit unknown units and invalid maund weights require review. The saved
calculation includes the agreed rate/unit and an equivalent per-maund figure.
This changes new calculations, not historical posted bills or source prices.

The real authenticated bill endpoint covers both Corn and Sesame, equivalent
KG/MAUND/MT prices, legacy records, Corn KAT, ignored client-entered totals, original
provisional-journal preservation and byte-identical rejection of unknown units.
The actual preview function has matching numeric/label and invalid-unit checks.
Existing ready-rice billing remains a separate validation path.

This corrects price-unit calculation. It does not certify the still-open outside-
mill native Corn loading route, all source-to-bill allocation variants, independent
physical-stock reconciliation, or whole-application/security/migration sign-off.
