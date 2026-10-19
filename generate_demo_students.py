#!/usr/bin/env python3
"""Generate a bulk-import CSV for the Edus demo tenant — 1,200 pupils, JSS 1–SS 3.

Columns match the template exactly (QuickAdmissionController::downloadTemplate):
First Name, Middle Name, Last Name, Gender (M/F), DOB, Religion, Blood Group,
Genotype, Home Town, Admission Number (blank = auto), Class Name, Parent Name,
Parent Phone.

Why 1,200 and why JSS 1–SS 3: the product is sold to senior-secondary schools, and a
demo holding 40 pupils per class never hits a page-size boundary, a slow debtors query
or a "scroll through 60 families" list. 1,200 across 18 classes is the shape of a real
customer.

Two things this deliberately does that the old 360-row generator did not:

1. **Siblings share a guardian** — roughly a fifth of families send two children. The
   debtors report groups by guardian, so a demo with one guardian per pupil proves
   nothing about the report's main feature.
2. **The golden-thread family is pinned.** Arraghibun Bello is the first row, in JSS 1A,
   with phone 7058813998 — the demo parent login (`7058813998@edus.ng`). Re-importing
   this file keeps the parent-portal walkthrough working.

Usage:
    python3 generate_demo_students.py                      # 1200 rows
    python3 generate_demo_students.py --count 360 --out demo_students_360.csv
    python3 generate_demo_students.py --siblings 0         # no shared guardians
"""
import argparse
import csv
import random
from collections import OrderedDict

MALE_FIRST = [
    "Ibrahim", "Musa", "Yusuf", "Abdullahi", "Sani", "Umar", "Bashir", "Kabir",
    "Suleiman", "Tunde", "Emeka", "Chinedu", "Obinna", "Segun", "Ahmed", "Bala",
    "Femi", "Jibril", "Kunle", "Lukman", "Nnamdi", "Olumide", "Peter", "Sadiq",
    "Tajudeen", "Wale", "Zubairu", "Ikenna", "Ado", "Babatunde",
]
FEMALE_FIRST = [
    "Amina", "Fatima", "Hauwa", "Aisha", "Zainab", "Halima", "Mariam", "Khadija",
    "Maryam", "Bilkisu", "Ngozi", "Chioma", "Adaeze", "Chiamaka", "Yetunde",
    "Titilayo", "Funke", "Rahma", "Safiya", "Salamatu", "Hadiza", "Jamila",
    "Kaltum", "Ladi", "Olamide", "Blessing", "Chika", "Folake", "Ese", "Ruqayya",
]
SURNAMES = [
    "Bello", "Ibrahim", "Musa", "Abdullahi", "Yakubu", "Abubakar", "Mohammed",
    "Okafor", "Adeyemi", "Okonkwo", "Balogun", "Danjuma", "Eze", "Garba",
    "Hassan", "Isah", "Jibrin", "Lawal", "Mahmud", "Nasir", "Ogunleye", "Sani",
    "Tanko", "Usman", "Yusuf", "Adamu", "Bala", "Chukwu", "Dauda", "Nwosu",
]
TITLES = ["Mr.", "Mrs.", "Alhaji", "Hajiya", "Dr.", "Engr.", "Mallam"]
PARENT_FIRST = [
    "Abdullahi", "Hauwa", "Musa", "Aisha", "Ibrahim", "Zainab", "Sani", "Fatima",
    "Yusuf", "Maryam", "Bello", "Halima", "Ahmed", "Khadija", "Umar", "Amina",
]
RELIGIONS = ["Islam", "Islam", "Islam", "Christianity", "Christianity"]
BLOOD = ["O+", "O+", "O+", "A+", "B+", "AB+", "O-"]
GENOTYPE = ["AA", "AA", "AA", "AS", "AS", "SS"]
TOWNS = [
    "Katsina", "Kano", "Kaduna", "Daura", "Malumfashi", "Funtua", "Zaria",
    "Sokoto", "Gusau", "Bauchi", "Jos", "Abuja", "Ilorin", "Maiduguri",
]

# Birth-year window per level — a JSS 1 pupil is not born in 2008.
BIRTH_YEARS = {
    "JSS 1": (2013, 2014),
    "JSS 2": (2012, 2013),
    "JSS 3": (2011, 2012),
    "SS 1": (2010, 2011),
    "SS 2": (2009, 2010),
    "SS 3": (2008, 2009),
}

HEADER = [
    "First Name", "Middle Name", "Last Name", "Gender (M/F)", "DOB (YYYY-MM-DD)",
    "Religion", "Blood Group", "Genotype", "Home Town",
    "Admission Number (Leave blank to auto-generate)", "Class Name",
    "Parent Name", "Parent Phone",
]

GOLDEN_PHONE = "7058813998"   # the demo parent login: 7058813998@edus.ng


def level_of(cls: str) -> str:
    """'JSS 1A' -> 'JSS 1' (longest match, so 'SS 1' never eats 'JSS 1')."""
    matches = [lvl for lvl in BIRTH_YEARS if cls.startswith(lvl)]
    if not matches:
        raise SystemExit(f"No birth-year window for class {cls!r}")
    return max(matches, key=len)


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate a demo pupil import CSV.")
    parser.add_argument("--count", type=int, default=1200)
    parser.add_argument("--out", default="demo_students_1200.csv")
    parser.add_argument("--levels", default="JSS 1,JSS 2,JSS 3,SS 1,SS 2,SS 3")
    parser.add_argument("--arms", default="A,B,C")
    parser.add_argument("--siblings", type=float, default=0.18,
                        help="Chance that the next family sends a second child (0-1).")
    parser.add_argument("--seed", type=int, default=2026)
    args = parser.parse_args()

    random.seed(args.seed)

    levels = [item.strip() for item in args.levels.split(",") if item.strip()]
    arms = [arm.strip() for arm in args.arms.split(",") if arm.strip()]
    classes = [f"{level}{arm}" for level in levels for arm in arms]
    per_class = max(1, -(-args.count // len(classes)))   # ceil: 1200 / 18 -> 67

    used_phones: set[str] = set()
    used_phones.add(GOLDEN_PHONE)

    def phone() -> str:
        while True:
            candidate = random.choice(["70", "80", "81", "90", "91"]) + "".join(
                random.choice("0123456789") for _ in range(8))
            if candidate not in used_phones:
                used_phones.add(candidate)
                return candidate

    def guardian() -> tuple[str, str]:
        surname = random.choice(SURNAMES)
        return f"{random.choice(TITLES)} {random.choice(PARENT_FIRST)} {surname}", phone()

    def pupil(cls: str, parent: str, parent_phone: str) -> list[str]:
        lo, hi = BIRTH_YEARS[level_of(cls)]
        gender = random.choice(["M", "F"])
        first = random.choice(MALE_FIRST if gender == "M" else FEMALE_FIRST)
        return [
            first, "", random.choice(SURNAMES), gender,
            f"{random.randint(lo, hi)}-{random.randint(1, 12):02d}-{random.randint(1, 28):02d}",
            random.choice(RELIGIONS), random.choice(BLOOD), random.choice(GENOTYPE),
            random.choice(TOWNS), "", cls, parent, parent_phone,
        ]

    # The golden-thread family, always first: the parent-portal walkthrough depends on it.
    rows: list[list[str]] = [[
        "Arraghibun", "", "Bello", "F", "2012-05-14", "Islam", "O+", "AA",
        "Kano", "", "JSS 1A", "Mrs. Hauwa Bello", GOLDEN_PHONE,
    ]]
    class_counts: "OrderedDict[str, int]" = OrderedDict(
        (cls, 1 if cls == "JSS 1A" else 0) for cls in classes
    )

    def room() -> list[str]:
        return [cls for cls in classes if class_counts[cls] < per_class]

    while len(rows) < args.count:
        available = room()
        if not available:
            break

        if random.random() < args.siblings and len(rows) + 1 < args.count:
            first_cls = available[0]
            others = [cls for cls in available if cls != first_cls]
            if others:
                parent, parent_phone = guardian()
                for cls in (first_cls, random.choice(others)):
                    rows.append(pupil(cls, parent, parent_phone))
                    class_counts[cls] += 1
                continue

        cls = random.choice(available)
        parent, parent_phone = guardian()
        rows.append(pupil(cls, parent, parent_phone))
        class_counts[cls] += 1

    with open(args.out, "w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(HEADER)
        writer.writerows(rows)

    families: dict[str, int] = {}
    for row in rows:
        families[row[12]] = families.get(row[12], 0) + 1
    multi = sum(1 for count in families.values() if count > 1)

    print(f"Wrote {args.out}")
    print(f"  pupils   : {len(rows)}")
    print(f"  classes  : {len(classes)}  ({', '.join(classes)})")
    print(f"  families : {len(families)}  ({multi} with more than one child)")
    print(f"  golden   : Arraghibun Bello · JSS 1A · {GOLDEN_PHONE}")
    print("  per class: " + ", ".join(f"{cls}={class_counts[cls]}" for cls in classes))


if __name__ == "__main__":
    main()
