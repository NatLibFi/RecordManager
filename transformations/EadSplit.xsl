<?xml version="1.0" encoding="UTF-8"?>

<!-- Split EAD records into separate items -->

<xsl:stylesheet
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns="urn:isbn:1-931666-22-9"
    exclude-result-prefixes="xsi xsl"
    version="1.1">
    
    <xsl:output method="xml" indent="no" encoding="UTF-8"/>
    
    <xsl:variable name="id-sep" select="'__'"/>

    <xsl:template match="/ead">
        <xsl:variable name="faId" select="concat(eadheader/eadid/@mainagencycode,$id-sep,eadheader/eadid/@identifier)"/>
        <xsl:element name="records">
            <xsl:apply-templates select="archdesc | archdesc/dsc//*[@level]" mode="split">
                <xsl:with-param name="fa-id" select="$faId"/>
            </xsl:apply-templates> 
        </xsl:element>
    </xsl:template>
    
    <xsl:template match="*" mode="split">
        <xsl:param name="fa-id"/>
        <xsl:variable name="id">
            <xsl:call-template name="build-item-id">
                <xsl:with-param name="el" select="."/>
                <xsl:with-param name="fa-id" select="concat($fa-id, $id-sep)"/>
            </xsl:call-template>
        </xsl:variable>
        <!-- repository -->
        <xsl:variable name="grepo" select="ancestor::ead//repository[1]"/>
        <xsl:variable name="repo">
            <xsl:choose>
                <xsl:when test="repository or did/repository">
                    <xsl:value-of select="repository|did/repository"/>
                </xsl:when>
                <xsl:otherwise><xsl:value-of select="$grepo"/></xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <!-- collection -->
        <xsl:variable name="gcoll" select="ancestor::ead/eadheader/filedesc/titlestmt/titleproper"/>
        <xsl:if test="$id != ''">
            <ead-item>
                <xsl:attribute name="id"><xsl:value-of select="$id"/></xsl:attribute>
                <xsl:attribute name="rep"><xsl:value-of select="$repo"/></xsl:attribute>
                <collection eadid="{$fa-id}"><xsl:value-of select="$gcoll"/></collection>
                <xsl:choose>
                    <xsl:when test="self::eadheader">
                    </xsl:when>
                    <xsl:when test="self::archdesc">
                        <c level="otherlevel" otherlevel="archdesc" xmlns:ead="urn:isbn:1-931666-22-9">
                            <xsl:apply-templates select="@*|*[not(self::dsc)]"/> 
                        </c>
                    </xsl:when>
                    <xsl:otherwise>
                        <c xmlns:ead="urn:isbn:1-931666-22-9">
                            <xsl:apply-templates select="@*|*[not(@level)]"/>   
                        </c>
                    </xsl:otherwise>
                </xsl:choose>
                <add-data>
                    <xsl:if test="ancestor::*[did][1]">
	                    <xsl:apply-templates select="/ead/archdesc[1]" mode="absolute-parent">
	                        <xsl:with-param name="fa-id" select="concat($fa-id, $id-sep)"/>
	                    </xsl:apply-templates>
	                </xsl:if>
                    <xsl:apply-templates select="ancestor::*[did][1]" mode="parent">
                        <xsl:with-param name="fa-id" select="concat($fa-id, $id-sep)"/>
                    </xsl:apply-templates>
                    <xsl:if test="*[@level]|dsc/*[@level]">
                        <children xmlns:ead="urn:isbn:1-931666-22-9">
                            <xsl:apply-templates select="*[@level]|dsc/*[@level]" mode="child">
                                <xsl:with-param name="fa-id" select="concat($fa-id, $id-sep)"/>
                            </xsl:apply-templates>
                        </children>
                    </xsl:if>
                </add-data>
            </ead-item>
        </xsl:if>
    </xsl:template>
    
    <xsl:template match="*" mode="absolute-parent">
        <xsl:param name="fa-id" select="''"/>
        <absolute-parent xmlns:ead="urn:isbn:1-931666-22-9">
            <xsl:attribute name="id">
                <xsl:call-template name="build-item-id">
                    <xsl:with-param name="el" select="."/>
                    <xsl:with-param name="fa-id" select="$fa-id"/>
                </xsl:call-template>
            </xsl:attribute>
            <xsl:copy-of select="@*"/>
            <xsl:apply-templates select="did/unitid|did/unittitle|did/unitdate"/>
        </absolute-parent>
    </xsl:template>
    <xsl:template match="*" mode="parent">
        <xsl:param name="fa-id" select="''"/>
        <parent xmlns:ead="urn:isbn:1-931666-22-9">
            <xsl:attribute name="id">
                <xsl:call-template name="build-item-id">
                    <xsl:with-param name="el" select="."/>
                    <xsl:with-param name="fa-id" select="$fa-id"/>
                </xsl:call-template>
            </xsl:attribute>
            <xsl:copy-of select="@*"/>
            <xsl:apply-templates select="did/unitid|did/unittitle|did/unitdate"/>
        </parent>
    </xsl:template>
    <xsl:template match="*" mode="child">
        <xsl:param name="fa-id" select="''"/>
        <child xmlns:ead="urn:isbn:1-931666-22-9">
            <xsl:attribute name="id">
                <xsl:call-template name="build-item-id">
                    <xsl:with-param name="el" select="."/>
                    <xsl:with-param name="fa-id" select="$fa-id"/>
                </xsl:call-template>
            </xsl:attribute>
            <xsl:copy-of select="@*"/>
            <xsl:apply-templates select="did/unitid|did/unittitle|did/unitdate"/>
        </child>
    </xsl:template>
    
    <xsl:template match="did">
        <!-- Copy 1 did -->
        <xsl:element name="{name()}" namespace="{namespace-uri()}">
            <xsl:apply-templates select="@*|node()" />
            <xsl:if test="not(origination)">
                <xsl:apply-templates select="ancestor::*[did/origination][1]/did/origination"/>
            </xsl:if>
        </xsl:element>
    </xsl:template>
    
    <xsl:template name="build-item-id">
        <xsl:param name="el"/>
        <xsl:param name="fa-id"/>
        <xsl:variable name="el-id">
            <xsl:choose>
                <xsl:when test="did/unitid">
                    <xsl:value-of select="did/unitid"/>
                </xsl:when>
                <xsl:when test="@id"><xsl:value-of select="@id"/></xsl:when>
                <!-- <xsl:otherwise><xsl:value-of select="generate-id()"/></xsl:otherwise> -->
            </xsl:choose>
        </xsl:variable>
        <xsl:value-of select="concat($fa-id, $el-id)"/>
    </xsl:template>
    
    <xsl:template match="*" mode="copy">
        <xsl:element name="{name()}" namespace="{namespace-uri()}">
            <xsl:apply-templates select="@*|node()" />
        </xsl:element>
    </xsl:template>
    <xsl:template match="*">
        <xsl:element name="{name()}" namespace="{namespace-uri()}">
            <xsl:apply-templates select="@*|node()" />
        </xsl:element>
    </xsl:template>
    <xsl:template match="@*|text()">
        <xsl:copy><xsl:apply-templates /></xsl:copy>
    </xsl:template>
    
</xsl:stylesheet>
