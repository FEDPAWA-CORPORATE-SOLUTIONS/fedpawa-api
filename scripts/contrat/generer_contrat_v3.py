#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Génération du contrat de domiciliation en PDF v2 - Version Times New Roman + Logo + En-tête/Pied de page"""

from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_JUSTIFY, TA_LEFT, TA_CENTER
from reportlab.lib.colors import HexColor, black
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, HRFlowable, Image
from reportlab.pdfgen import canvas
import os

# Logo path
LOGO_PATH = "/root/.hermes/cache/documents/native-inputs/ce29ed18b61849f09cce5814c2f610c581a4126b525ea93a6c9ccc56727273c4/img_fe45f543312c.jpg"

# Colors
COLOR_PRIMARY = HexColor("#15294D")
COLOR_GOLD = HexColor("#D4AF37")
COLOR_BORDER = HexColor("#999999")

# Fonts
FONT_NAME = "Times-Roman"
FONT_NAME_BOLD = "Times-Bold"

# Styles
style_title = ParagraphStyle(
    'Title', fontName=FONT_NAME_BOLD, fontSize=18,
    textColor=COLOR_PRIMARY, alignment=TA_JUSTIFY, spaceAfter=14
)

style_h2 = ParagraphStyle(
    'H2', fontName=FONT_NAME_BOLD, fontSize=12.5,
    textColor=COLOR_PRIMARY, spaceBefore=14, spaceAfter=6,
    borderWidth=0.8, borderColor=COLOR_GOLD,
    borderPadding=(0, 0, 2, 0), borderRadius=1
)

style_normal = ParagraphStyle(
    'Normal', fontName=FONT_NAME, fontSize=10,
    leading=14, textColor=black, alignment=TA_JUSTIFY, spaceAfter=6
)

style_bold = ParagraphStyle(
    'Bold', fontName=FONT_NAME_BOLD,
    fontSize=10, leading=14, textColor=black, alignment=TA_LEFT, spaceAfter=6
)

style_footer = ParagraphStyle(
    'Footer', fontName=FONT_NAME, fontSize=9,
    textColor=HexColor("#777777"), alignment=TA_LEFT,
    spaceBefore=10
)

def P(text, style=style_normal):
    return Paragraph(text, style)

def H(text):
    return Paragraph(text, style_h2)

def B(text):
    return Paragraph(text, style_bold)

# Header/Footer callback
def header_footer(canvas_obj, doc):
    canvas_obj.saveState()
    w, h = A4
    
    # === HEADER ===
    # Logo à gauche
    logo_w = 30 * mm
    logo_h = logo_w * 0.55  # ratio approximatif
    canvas_obj.drawImage(
        LOGO_PATH,
        15 * mm,
        h - 20 * mm,
        width=logo_w,
        height=logo_h,
        preserveAspectRatio=True,
        mask='auto'
    )
    
    # Texte en haut à droite
    canvas_obj.setFont(FONT_NAME_BOLD, 10)
    canvas_obj.setFillColor(COLOR_PRIMARY)
    canvas_obj.drawRightString(
        w - 15 * mm,
        h - 12 * mm,
        "FEDPAWA CORPORATE SOLUTIONS SARL"
    )
    canvas_obj.setFont(FONT_NAME, 8)
    canvas_obj.setFillColor(HexColor("#666666"))
    canvas_obj.drawRightString(
        w - 15 * mm,
        h - 18 * mm,
        "Convention de Domiciliation"
    )
    canvas_obj.setStrokeColor(HexColor("#666666"))
    canvas_obj.setLineWidth(0.5)
    canvas_obj.line(15 * mm, h - 22 * mm, w - 15 * mm, h - 22 * mm)
    
    # === FOOTER ===
    canvas_obj.setStrokeColor(HexColor("#666666"))
    canvas_obj.setLineWidth(0.5)
    canvas_obj.line(15 * mm, 18 * mm, w - 15 * mm, 18 * mm)
    
    canvas_obj.setFont(FONT_NAME, 8)
    canvas_obj.setFillColor(HexColor("#666666"))
    canvas_obj.drawCentredString(
        w / 2.0,
        12 * mm,
        "Contrats- Douala – Lognom (Pharmacie de Lognom Immeuble) : Tel: 691704802   Email: contact@fedpawa.com   Site web : www.fedpawa.com"
    )
    canvas_obj.drawCentredString(
        w / 2.0,
        6 * mm,
        f"Page {canvas_obj.getPageNumber()}"
    )
    
    canvas_obj.restoreState()

# Create document
doc = SimpleDocTemplate(
    "/tmp/contrat_domiciliation_rempli_v3.pdf",
    pagesize=A4,
    leftMargin=15 * mm,
    rightMargin=15 * mm,
    topMargin=25 * mm,
    bottomMargin=22 * mm,
    title="Convention de Domiciliation - SOLUTIONS DIGITALES SARL",
    author="FEDPAWA CORPORATE SOLUTIONS SARL",
)

story = []

# ==================== EN-TÊTE (déjà dans header via callback) ====================
# Pas besoin de répéter ici, c'est dans header_footer

# ==================== CORPS ====================

# Préambule
story.append(P(
    "<b>Le présent contrat est conclu entre, d'une part,</b>", style_normal
))
story.append(P(
    "<b>FEDPAWA CORPORATE SOLUTIONS SARL</b>, Société à Responsabilité Limitée, immatriculée au Registre du Commerce et du Crédit Mobilier de Douala sous le numéro <b>CM-DLA-01-2026-B13-00767</b>, Numéro d'Identifiant Unique (NIU) <b>M09261890362B</b>, dont le siège social est situé à l'Immeuble Pharmacie de Logpom, Douala, Cameroun, représentée par son Gérant dûment habilité Mme FEDJIO,", style_normal
))
story.append(P("<b>D'UNE PART,</b>", style_bold))
story.append(Spacer(1, 2))
story.append(P("ET,", style_bold))
story.append(Spacer(1, 4))

story.append(P(
    "<b>SOLUTIONS DIGITALES SARL</b>, <b>SARL</b>, immatriculée au Registre du Commerce et du Crédit Mobilier sous le numéro <b>CM-DLA-01-2026-B13-01234</b> (ou en cours d'immatriculation), Numéro d'Identifiant Unique (NIU) <b>M092618903789A</b> (ou en cours d'attribution), dont le siège social est situé à <b>Immeuble Akwa Central, 4ème étage, Douala-Bonaprisa, Cameroun</b>, représentée par <b>Marie Tchoumi</b> agissant en qualité de <b>Gérante / Fondateur</b> (ci-après dénommée « Le Domicilié »),<br/><b>D'AUTRE PART ;</b>", style_normal
))

story.append(HRFlowable(width="100%", thickness=0.8, color=COLOR_GOLD, spaceBefore=8, spaceAfter=8))

# ARTICLES
story.append(H("ARTICLE 1 : OBJET DE LA CONVENTION"))
story.append(P(
    "La présente convention a pour objet la mise à disposition, par le Domiciliataire au profit du Domicilié, d'une adresse de siège social ainsi que des services associés, dans les conditions strictement définies aux présents articles. Cette mise à disposition est consentie dans le respect des dispositions impératives de l'article 24 de l'Acte Uniforme OHADA relatif au Droit des Sociétés Commerciales et du Groupement d'Intérêt Économique (AUSCGIE), qui impose que le siège social soit un lieu déterminé, réel et localisable où l'entreprise peut être contactée. Le Domiciliataire met ainsi à disposition du Domicilié une adresse physique réelle et identifiable, située à l'Immeuble Pharmacie de Logpom, en bordure de route, Logpom, Douala, Cameroun."
))

story.append(H("ARTICLE 2 : NATURE JURIDIQUE DE LA CONVENTION"))
story.append(P(
    "La présente convention est un contrat de prestation de services. Elle ne constitue en aucun cas un bail commercial, un bail professionnel, ni un bail à usage d'habitation au sens des dispositions du droit OHADA et du droit camerounais. En conséquence, le Domicilié ne bénéficie d'aucun droit au renouvellement, d'aucune propriété commerciale, ni d'aucun droit de maintien dans les lieux à l'expiration de la présente convention. La présente convention est conclue intuitu personae, en considération exclusive de la personne du Domicilié. Elle est strictement incessible et intransmissible."
))

story.append(H("ARTICLE 3 : ENTRÉE EN VIGUEUR, DURÉE ET CONDITION SUSPENSIVE"))
story.append(P(
    "La présente convention entre en vigueur à la date de sa signature électronique par le Domicilié et du règlement effectif de la première échéance. Cette entrée en vigueur est soumise à la condition suspensive de la validation définitive, par le Domiciliataire, des pièces justificatives fournies au titre du dispositif KYC (Article 8). À défaut de validation dans un délai de quarante-huit (48) heures ouvrées, la convention sera réputée caduque et les sommes versées seront intégralement restituées au Domicilié."
))
story.append(P(
    "La convention est conclue pour la durée sélectionnée lors de la souscription : <b>12 mois (Engagement annuel)</b>"
))
story.append(P(
    "À l'expiration de la période initiale, la convention se renouvellera automatiquement par tacite reconduction pour une durée identique, sauf dénonciation par l'une des parties, notifiée par écrit quinze (15) jours au moins avant la date d'échéance."
))

story.append(H("ARTICLE 4 : FORMULE SOUSCRITE ET GRILLE TARIFAIRE"))
story.append(P("Le Domicilié souscrit à l'une des formules suivantes :"))
story.append(P("<b>PACK PREMIUM (Gamme Nationale) : 70 000 FCFA / mois</b>"))
story.append(P(
    "En cas de paiement en devises (USD), le taux de change appliqué sera celui en vigueur à la date d'émission de la facture."
))

story.append(H("ARTICLE 5 : MODALITÉS D'EXÉCUTION DES SERVICES"))
story.append(P("1.) Gestion des bureaux et des heures incluses : L'utilisation des heures de bureau privé incluses dans le forfait est soumise à une réservation préalable obligatoire."))
story.append(P("a) Les réservations doivent être effectuées au moins quarante-huit (48) heures à l'avance via l'espace client ou par WhatsApp."))
story.append(P("b) L'accès au bureau est conditionné à la disponibilité de l'espace au moment de la demande."))
story.append(P("c) Toute annulation d'une réservation doit être notifiée au moins vingt-quatre (24) heures à l'avance. À défaut, l'heure réservée sera automatiquement déduite du quota mensuel."))
story.append(P("2.) Politique de dépassement : Toute heure consommée au-delà du quota mensuel inclus fera l'objet d'une facturation supplémentaire, selon la grille tarifaire en vigueur."))
story.append(P("a) Toute heure entamée est due dans son intégralité."))
story.append(P("b) Les heures non consommées dans le mois ne sont ni reportables ni remboursables."))
story.append(P("3.) Gestion du courrier et des colis :"))
story.append(P("a) Le Domiciliataire procède à la numérisation du courrier standard dans un délai de vingt-quatre (24) à quarante-huit (48) heures ouvrées suivant sa réception."))
story.append(P("b) Les colis volumineux ou les plis recommandés font l'objet d'une notification immédiate. Le Domicilié s'engage à les retirer dans un délai maximum de sept (7) jours ouvrés. Passé ce délai, des frais de stockage journaliers seront appliqués."))
story.append(P("4.) Services téléphoniques : Les services de téléphonie (numéros locaux) dépendent exclusivement des réseaux des opérateurs de télécommunications locaux agréés. Le Domiciliataire ne saurait être tenu responsable des interruptions de réseau, des pannes techniques ou des suspensions de lignes décidées unilatéralement par ces opérateurs."))

story.append(H("ARTICLE 6 : OBLIGATIONS DU DOMICILIATAIRE"))
story.append(P("Le Domiciliataire s'oblige à :"))
story.append(P("1.) Mettre à disposition une adresse de siège social conforme aux exigences légales."))
story.append(P("2.) Réceptionner, trier, identifier et numériser le courrier destiné au Domicilié."))
story.append(P("3.) Téléverser les courriers numérisés dans l'espace client sécurisé ou les transmettre par canal crypté."))
story.append(P("4.) Notifier le Domicilié par email et WhatsApp à chaque réception."))
story.append(P("5.) Conserver le courrier physique conformément aux dispositions de l'Article 11."))
story.append(P("6.) Assurer la confidentialité absolue des documents et correspondances reçus."))

story.append(H("ARTICLE 7 : OBLIGATIONS DU DOMICILIÉ"))
story.append(P("Le Domicilié s'oblige à :"))
story.append(P("1.) Utiliser l'adresse exclusivement pour son siège social et sa correspondance professionnelle."))
story.append(P("2.) Ne pas sous-louer, prêter ou partager l'adresse de domiciliation avec un tiers. Toute violation de cette clause entraînera la résiliation immédiate de la convention, sans remboursement."))
story.append(P("3.) Ne pas utiliser l'adresse pour des activités illicites, frauduleuses ou contraires à l'ordre public et aux bonnes mœurs."))
story.append(P("4.) Informer le Domiciliataire, sous quinze (15) jours, de toute modification statutaire (dénomination, forme, représentants légaux)."))
story.append(P("5.) S'acquitter de l'intégralité des sommes dues."))
story.append(P("6.) Fournir les documents requis pour le KYC (Article 8)."))

story.append(H("ARTICLE 8 : DISPOSITIF KYC (KNOW YOUR CUSTOMER)"))
story.append(P(
    "L'accès aux infrastructures est strictement soumis à la vérification d'identité et à la conformité réglementaire. Le Domicilié s'engage à fournir une copie certifiée des pièces d'identité des dirigeants, les statuts (ou projets de statuts), un justificatif de domicile du représentant légal, ainsi qu'une description transparente des activités. Le Domiciliataire se réserve le droit discrétionnaire de refuser ou de résilier toute domiciliation ne répondant pas à ces critères."
))

story.append(H("ARTICLE 9 : CONDITIONS FINANCIÈRES ET PÉNALITÉS"))
story.append(P(
    "Le Domicilié s'engage à payer l'abonnement selon la formule souscrite, payable d'avance, par Orange Money, MTN Mobile Money, carte bancaire ou virement. Tout retard de paiement excédant dix (10) jours entraînera la suspension immédiate des services et l'application de plein droit d'une pénalité de cinq pour cent (5%) par mois de retard sur les sommes dues. Après mise en demeure restée sans effet pendant quinze (15) jours, la convention sera résiliée de plein droit. Les prestations annexes et dépassements sont facturés selon la grille tarifaire annexée."
))

story.append(H("ARTICLE 10 : RÉSILIATION"))
story.append(P(
    "La convention peut être résiliée par le Domicilié à tout moment, avec un préavis de quinze (15) jours avant l'échéance de la période en cours, notifié par écrit via l'espace client ; les sommes correspondant à la période en cours restent dues dans leur intégralité. Elle peut également être résiliée par le Domiciliataire en cas de manquement grave (non-paiement, fraude, non-respect du KYC ou partage de l'adresse), après mise en demeure restée sans effet pendant quinze (15) jours. En cas de résiliation, les sommes déjà versées pour la période engagée restent définitivement acquises au Domiciliataire."
))

story.append(H("ARTICLE 11 : SORT DU COURRIER À LA FIN DU CONTRAT"))
story.append(P(
    "À la date d'expiration ou de résiliation, l'autorisation d'utiliser l'adresse prend fin immédiatement et de plein droit. Le Domiciliataire conservera le courrier physique pendant trente (30) jours. Passé ce délai, et après notification par email restée sans réponse, le courrier sera détruit. Le Domiciliataire se réserve le droit de remettre le courrier à un huissier de justice aux frais exclusifs du Domicilié si la nature du courrier l'exige (actes d'huissier, lettres recommandées)."
))

story.append(H("ARTICLE 12 : LIMITATION DE RESPONSABILITÉ"))
story.append(P(
    "La responsabilité du Domiciliataire ne pourra en aucun cas être engagée au-delà du montant total des abonnements perçus au cours des trois (3) derniers mois précédant l'incident, en cas de perte, de détérioration ou de mauvaise orientation d'un courrier ou d'un colis."
))

story.append(H("ARTICLE 13 : FORCE MAJEURE"))
story.append(P(
    "Le Domiciliataire ne pourra être tenu responsable d'un manquement à ses obligations si celui-ci résulte d'un cas de force majeure (incendie, inondation, coupure généralisée d'électricité ou d'internet, cyberattaque, décision gouvernementale)."
))

story.append(H("ARTICLE 14 : RÉVISION DES TARIFS"))
story.append(P(
    "Le Domiciliataire se réserve le droit de réviser ses tarifs une fois par an, en informant le Domicilié par écrit au moins trente (30) jours avant l'entrée en vigueur des nouveaux prix."
))

story.append(H("ARTICLE 15 : PROTECTION DES DONNÉES PERSONNELLES"))
story.append(P(
    "Conformément à la législation camerounaise en vigueur, le Domiciliataire s'engage à protéger les données personnelles du Domicilié et à ne les utiliser que pour les stricts besoins de l'exécution de la convention. Le Domicilié dispose d'un droit d'accès, de rectification et de suppression de ses données."
))

story.append(H("ARTICLE 16 : LOI APPLICABLE ET JURIDICTION COMPÉTENTE"))
story.append(P(
    "La présente convention est régie par le droit camerounais et les Actes Uniformes de l'OHADA. Tout litige sera soumis, à défaut de règlement amiable sous quinze (15) jours, à la compétence exclusive des tribunaux de Douala, Cameroun."
))

story.append(H("ARTICLE 17 : SIGNATURE ÉLECTRONIQUE"))
story.append(P(
    "La présente convention est valablement signée par voie électronique. Conformément à la Loi n° 2010/012 du 21 décembre 2010 relative à la cybersécurité et à la cybercriminalité au Cameroun, la signature électronique a la même valeur juridique que la signature manuscrite. Le Domicilié reconnaît avoir lu, compris et accepté l'intégralité des clauses."
))

story.append(Spacer(1, 12))
story.append(P("ACCEPTATION ET SIGNATURE DU DOMICILIÉ", style_h2))
story.append(P(
    "Je soussigné(e) <b>Marie Tchoumi</b>, agissant en qualité de <b>Gérante / Fondateur</b> de la société <b>SOLUTIONS DIGITALES SARL</b>, déclare avoir lu et compris l'intégralité de la présente convention de domiciliation, y compris son annexe. J'accepte sans réserve l'ensemble des conditions qui y sont stipulées."
))
story.append(P("Fait à Douala, par voie électronique."))
story.append(P("\u2610 J'accepte les conditions de la convention de domiciliation.", style_bold))
story.append(P("Signature électronique du Domicilié : ______________________", style_bold))
story.append(P("Date et heure (Horodatage certifié) : <b>25/11/2026 à 14:32</b>", style_bold))
story.append(P("Code de validation SMS/Email : <b>482917</b>", style_bold))
story.append(P("Adresse IP : <b>192.168.1.105</b>", style_bold))

story.append(Spacer(1, 20))
story.append(P("FEDPAWA Corporate Solutions · Immeuble Pharmacie de Logpom, Douala · Cameroun · contact@fedpawa.com", style_footer))

# Generate PDF with header/footer
doc.build(story, onFirstPage=header_footer, onLaterPages=header_footer)
print("PDF généré avec succès : /tmp/contrat_domiciliation_rempli_v3.pdf")
