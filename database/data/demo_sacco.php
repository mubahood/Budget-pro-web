<?php

/*
| The Akiba Ledger public demo (App\Services\Sacco\Demo\SaccoDemoBuilder, AK-35): one office-mode SACCO of teachers
| in Wakiso, with 60 made-up members and 18 months of books. Everything here is fictional: the people, their phone
| numbers (nothing is ever sent from a demo company: Messenger skips it), the ID numbers (they read DEMO, so they can
| never be a real card), the schools and the amounts. Amounts are in Uganda shillings.
|
| The sign-ins below are the demo's published credentials: the website's /demo page shows them, so they are not
| secrets. The password comes from config/demo.php (`sacco.password`), the same for every role.
*/
return [
    'sacco' => [
        'name' => "Wakiso Teachers' SACCO (Demo)",
        'short_name' => 'Wakiso Teachers (Demo)',
        'registration_no' => 'DEMO/CS/0000',
        'address' => 'Plot 7, Gombe Road, Wakiso (fictional)',
        'phone' => '+256 700 000 100',
        'email' => 'office@wakisoteachers.example',
        'domain' => 'wakisoteachers.example',
    ],

    // One login per role (the owner is seated as chairperson). Each signs in with <key>@domain above.
    'logins' => [
        'chair' => ['name' => 'Christopher Lwanga', 'role' => 'owner', 'position' => 'chairperson', 'label' => 'Chairperson (owner)'],
        'manager' => ['name' => 'Esther Nalule', 'role' => 'sacco_manager', 'position' => 'manager', 'label' => 'Manager'],
        'treasurer' => ['name' => 'Daniel Kawooya', 'role' => 'sacco_treasurer', 'position' => 'treasurer', 'label' => 'Treasurer'],
        'secretary' => ['name' => 'Harriet Nakabugo', 'role' => 'sacco_secretary', 'position' => 'secretary', 'label' => 'Secretary'],
        'loans' => ['name' => 'Ivan Ssentongo', 'role' => 'sacco_loans_officer', 'position' => null, 'label' => 'Loans officer'],
        'committee' => ['name' => 'Rose Atuhaire', 'role' => 'sacco_committee', 'position' => 'loans_committee', 'label' => 'Loans committee member'],
        'auditor' => ['name' => 'Peter Obbo', 'role' => 'sacco_auditor', 'position' => 'supervisory', 'label' => 'Auditor (supervisory board)'],
        'teller' => ['name' => 'Faith Nakayiza', 'role' => 'sacco_teller', 'position' => 'teller', 'label' => 'Teller'],
    ],

    // The other two seats of the loans committee (a majority of three decides). Neither is a demo login.
    'officers' => [
        ['name' => 'Moses Ekwaro', 'role' => 'sacco_committee', 'position' => 'loans_committee'],
        ['name' => 'Juliet Namagembe', 'role' => 'sacco_committee', 'position' => 'loans_committee'],
    ],

    // The member whose own sign-in opens /member: a teacher with savings in every product, shares and a loan.
    'member_login' => ['email' => 'member@wakisoteachers.example', 'member' => 0],

    'schools' => [
        'Kakiri Hillside Primary School', 'Nansana Valley Primary School', 'Kira Lakeview Secondary School', 'Matugga Riverside Primary School',
        'Kasangati Day Secondary School', 'Busukuma Junction Primary School', 'Gombe Hilltop College', 'Kyengera Parents\' Primary School',
        'Namayumba Community School', 'Wakiso Town Primary School',
    ],

    'subcounties' => ['Kakiri', 'Nansana', 'Kira', 'Gombe', 'Kasangati', 'Busukuma', 'Namayumba', 'Kyengera', 'Wakiso Town Council', 'Masulita'],

    // [first name, last name, gender, birth year]. The first 48 were members before the SACCO moved its books to Akiba
    // Ledger (they come in through the opening balances); the last 12 join during the 18 months.
    'members' => [
        ['Sarah', 'Nakato', 'female', 1984], ['Joseph', 'Ssemakula', 'male', 1979], ['Grace', 'Akello', 'female', 1988], ['Patrick', 'Okello', 'male', 1981],
        ['Esther', 'Namubiru', 'female', 1986], ['Robert', 'Mugisha', 'male', 1977], ['Ruth', 'Nansubuga', 'female', 1990], ['Emmanuel', 'Kato', 'male', 1985],
        ['Florence', 'Atim', 'female', 1982], ['Moses', 'Ssekandi', 'male', 1975], ['Harriet', 'Nabirye', 'female', 1987], ['Denis', 'Byaruhanga', 'male', 1983],
        ['Patience', 'Kyomugisha', 'female', 1991], ['Peter', 'Wasswa', 'male', 1980], ['Juliet', 'Nalwoga', 'female', 1985], ['Samuel', 'Opio', 'male', 1978],
        ['Brenda', 'Achieng', 'female', 1992], ['Julius', 'Tumwine', 'male', 1976], ['Proscovia', 'Nakimuli', 'female', 1980], ['Francis', 'Lubega', 'male', 1984],
        ['Annet', 'Tumusiime', 'female', 1989], ['Henry', 'Mukasa', 'male', 1973], ['Rose', 'Namutebi', 'female', 1979], ['David', 'Ochieng', 'male', 1986],
        ['Agnes', 'Nankya', 'female', 1983], ['Richard', 'Kizza', 'male', 1982], ['Christine', 'Auma', 'female', 1987], ['Ronald', 'Kiwanuka', 'male', 1988],
        ['Mary', 'Babirye', 'female', 1981], ['Brian', 'Ainomugisha', 'male', 1993], ['Doreen', 'Kemigisha', 'female', 1990], ['Charles', 'Muwonge', 'male', 1974],
        ['Josephine', 'Nambi', 'female', 1985], ['Simon', 'Odongo', 'male', 1980], ['Margaret', 'Apio', 'female', 1972], ['Godfrey', 'Nsubuga', 'male', 1979],
        ['Winnie', 'Nakalema', 'female', 1991], ['Isaac', 'Turyahikayo', 'male', 1987], ['Olivia', 'Asiimwe', 'female', 1989], ['Fred', 'Kasozi', 'male', 1976],
        ['Betty', 'Nassali', 'female', 1984], ['Michael', 'Waiswa', 'male', 1990], ['Immaculate', 'Kansiime', 'female', 1986], ['Thomas', 'Ogwang', 'male', 1978],
        ['Lydia', 'Namatovu', 'female', 1988], ['Andrew', 'Mwesigwa', 'male', 1983], ['Susan', 'Adong', 'female', 1982], ['Paul', 'Sserwadda', 'male', 1977],
        // joiners
        ['Irene', 'Nabukenya', 'female', 1994], ['George', 'Katende', 'male', 1992], ['Gloria', 'Ainembabazi', 'female', 1995], ['Martin', 'Ojok', 'male', 1991],
        ['Rebecca', 'Nalubega', 'female', 1993], ['Steven', 'Bwire', 'male', 1990], ['Joan', 'Akankwasa', 'female', 1996], ['Edward', 'Kakooza', 'male', 1989],
        ['Teddy', 'Nakawungu', 'female', 1994], ['Alex', 'Rwabwogo', 'male', 1992], ['Edith', 'Mbabazi', 'female', 1995], ['John', 'Mutebi', 'male', 1993],
    ],

    'founding' => 48,

    // Loan purposes a teacher gives (never a word about anyone's relatives: the demo text is read by a test).
    'purposes' => [
        'ORD' => ['Build two rental rooms in Kakiri', 'Buy a plot of land in Matugga', 'Roof the house at home', 'Start a poultry project', 'Buy a motorcycle for transport to school',
            'Expand a retail shop', 'Plant coffee on two acres', 'Finish the house floor and plaster'],
        'SCH' => ['School fees for the first term', 'University tuition for the second semester', 'School fees and uniforms'],
        'EMG' => ['Medical bills', 'Urgent repairs after a storm', 'Hospital bills after an accident'],
    ],
];
