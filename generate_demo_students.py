#!/usr/bin/env python3
"""Generate demo_students_360.csv for the Edus bulk-import module.

Matches the exact 13-column template of QuickAdmissionController::downloadTemplate():
First Name, Middle Name, Last Name, Gender (M/F), DOB (YYYY-MM-DD), Religion,
Blood Group, Genotype, Home Town, Admission Number (leave blank), Class Name,
Parent Name, Parent Phone.

360 students = 40 per class across JSS 1A..JSS 3C.
The golden-thread student "Arraghibun" is the first data row (JSS 1A).
"""
import csv
import random

random.seed(2026)

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
    "Sani", "Okonkwo", "Okafor", "Adeyemi", "Ogunleye", "Nwachukwu", "Eze",
    "Balogun", "Suleiman", "Tanko", "Umar", "Garba", "Lawal", "Obi", "Adeleke",
    "Ayodele", "Ekwueme", "Fashola", "Danjuma", "Oyelaran", "Aliyu", "Bashir",
    "Olayinka",
]
PARENT_FIRST = [
    "Rasheed", "Kemi", "Ali", "Binta", "Chukwudi", "Adaeze", "Solomon", "Grace",
    "Tanko", "Sadiya", "Emeka", "Funmilayo", "Yakubu", "Amina", "Femi", "Ngozi",
    "Usman", "Hauwa", "Olusegun", "Fatima",
]
RELIGIONS = ["Islam", "Christianity"]
BLOOD = ["O+", "A+", "B+", "AB+", "O-", "A-"]
GENOTYPE = ["AA", "AS", "SS", "AC"]
TOWNS = [
    "Kano", "Lagos", "Abuja", "Katsina", "Ibadan", "Enugu", "Port Harcourt",
    "Kaduna", "Sokoto", "Maiduguri", "Jos", "Benin City", "Zaria", "Ilorin",
    "Abeokuta", "Owerri", "Calabar", "Bauchi", "Gombe", "Minna",
]
TITLES = ["Mr.", "Mrs.", "Alh.", "Alhaja"]

CLASSES = [
    "JSS 1A", "JSS 1B", "JSS 1C",
    "JSS 2A", "JSS 2B", "JSS 2C",
    "JSS 3A", "JSS 3B", "JSS 3C",
]
PER_CLASS = 40

# Realistic birth years per level
CLASS_YEARS = {
    "JSS 1": (2011, 2013),
    "JSS 2": (2010, 2012),
    "JSS 3": (2009, 2011),
}

HEADER = [
    "First Name",
    "Middle Name",
    "Last Name",
    "Gender (M/F)",
    "DOB (YYYY-MM-DD)",
    "Religion",
    "Blood Group",
    "Genotype",
    "Home Town",
    "Admission Number (Leave blank to auto-generate)",
    "Class Name",
    "Parent Name",
    "Parent Phone",
]

used_phones = set()


def phone():
    while True:
        p = random.choice(["70", "80", "81", "90", "91"]) + "".join(
            random.choice("0123456789") for _ in range(8)
        )
        if p not in used_phones:
            used_phones.add(p)
            return p


def dob_for(level: str) -> str:
    lo, hi = CLASS_YEARS[level]
    y = random.randint(lo, hi)
    m = random.randint(1, 12)
    d = random.randint(1, 28)
    return f"{y:04d}-{m:02d}-{d:02d}"


def make_row(first, last, gender, level, cls, index):
    surname = random.choice(SURNAMES)
    title = random.choice(TITLES)
    parent = f"{title} {random.choice(PARENT_FIRST)} {surname}"
    return [
        first,
        "",
        last,
        gender,
        dob_for(level),
        random.choice(RELIGIONS),
        random.choice(BLOOD),
        random.choice(GENOTYPE),
        random.choice(TOWNS),
        "",
        cls,
        parent,
        phone(),
    ]


rows = []
# Golden-thread student first (JSS 1A)
rows.append([
    "Arraghibun", "", "Bello", "F", "2012-05-14", "Islam", "O+", "AA",
    "Kano", "", "JSS 1A", "Mrs. Hauwa Bello", phone(),
])

for cls in CLASSES:
    level = cls[:-1].rstrip()  # "JSS 1A" -> "JSS 1"
    made = 0
    for _ in range(PER_CLASS):
        if cls == "JSS 1A" and made == 0:
            # Arraghibun already occupies the first JSS 1A slot
            made += 1
            continue
        gender = random.choice(["M", "F"])
        first = random.choice(MALE_FIRST if gender == "M" else FEMALE_FIRST)
        last = random.choice(SURNAMES)
        rows.append(make_row(first, last, gender, level, cls, made))
        made += 1

with open("demo_students_360.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.writer(f)
    w.writerow(HEADER)
    w.writerows(rows)

total = len(rows)
by_class = {}
for r in rows:
    by_class[r[10]] = by_class.get(r[10], 0) + 1
print(f"Total data rows: {total}")
for c in CLASSES:
    print(f"  {c}: {by_class.get(c, 0)}")
